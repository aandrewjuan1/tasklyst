<?php

namespace App\Services\LLM\TaskAssistant;

use App\Enums\MessageRole;
use App\Enums\TaskAssistantIntent;
use App\Events\TaskAssistantToolCall;
use App\Events\TaskAssistantToolResult;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\Prioritization\TaskPrioritizationService;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskAssistantService
{
    private const MESSAGE_LIMIT = 50;

    private const STREAM_CHUNK_SIZE = 200;

    /** @var string[] */
    private const READ_ONLY_TOOL_KEYS = [
        'list_tasks',
    ];

    public function __construct(
        private readonly TaskAssistantPromptData $promptData,
        private readonly TaskAssistantSnapshotService $snapshotService,
        private readonly TaskAssistantToolInterpreter $toolInterpreter,
        private readonly TaskAssistantResponseProcessor $responseProcessor,
        private readonly TaskAssistantFlowPipeline $flowPipeline,
        private readonly TaskAssistantFlowExecutionEngine $flowExecutionEngine,
        private readonly TaskAssistantPrismTextDeltaExtractor $deltaExtractor,
        private readonly TaskAssistantStructuredFlowGenerator $structuredFlowGenerator,
        private readonly TaskAssistantStreamingBroadcaster $streamingBroadcaster,
        private readonly TaskAssistantToolEventPersister $toolEventPersister,
        private readonly TaskPrioritizationService $prioritizationService,
        private readonly TaskAssistantTaskChoiceConstraintsExtractor $constraintsExtractor,
    ) {}

    /**
     * Run a single request with tools and streaming; persist user and assistant messages.
     */
    public function streamResponse(TaskAssistantThread $thread, string $userMessageContent, TaskAssistantIntent $intent = TaskAssistantIntent::ProductivityCoaching): StreamedResponse
    {
        $user = $thread->user;
        $userId = (int) $user->id;
        $userMessage = $thread->messages()->create([
            'role' => MessageRole::User,
            'content' => $userMessageContent,
        ]);
        $assistantMessage = $thread->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => '',
        ]);

        $historyMessages = $this->loadHistoryMessages($thread, $userMessage->id);
        $prismMessages = $this->mapToPrismMessages($historyMessages);
        $prismMessages[] = new UserMessage($userMessageContent);
        $tools = $this->resolveToolsForIntent($user, $intent);
        $promptData = $this->promptData->forUser($user);
        $promptData['toolManifest'] = $this->buildToolManifestFromTools($tools);
        $snapshot = $this->snapshotService->buildForUser($user);
        Log::info('task-assistant.snapshot', [
            'user_id' => $user->id,
            'thread_id' => $thread->id,
            'snapshot' => $snapshot,
        ]);
        $promptData['snapshot'] = $snapshot;
        $timeout = (int) config('prism.request_timeout', 120);

        $this->bindTaskAssistantContext($thread->id, $assistantMessage->id);

        return response()->stream(function () use ($assistantMessage, $prismMessages, $tools, $promptData, $timeout, $userId): void {
            try {
                $pending = Prism::text()
                    ->using(Provider::Ollama, (string) config('task-assistant.model', 'hermes3:3b'))
                    ->withSystemPrompt(view('prompts.task-assistant-system', $promptData))
                    ->withMessages($prismMessages)
                    ->withTools($tools)
                    ->withMaxSteps(4)
                    ->withClientOptions(['timeout' => $timeout]);

                $fullText = '';
                /** @var array<string, true> */
                $seenToolCallIds = [];
                /** @var array<string, true> */
                $seenToolResultCallIds = [];

                if (method_exists($pending, 'asStream')) {
                    foreach ($pending->asStream() as $event) {
                        if ($event instanceof ToolCallEvent) {
                            $toolCall = $event->toolCall;

                            $this->toolEventPersister->persistToolCall(
                                assistantMessage: $assistantMessage,
                                toolCall: $toolCall,
                                seenToolCallIds: $seenToolCallIds
                            );

                            try {
                                broadcast(new TaskAssistantToolCall(
                                    userId: $userId,
                                    toolCallId: $toolCall->id,
                                    toolName: $toolCall->name,
                                    arguments: $toolCall->arguments(),
                                ));
                            } catch (\Throwable $e) {
                                Log::warning('task-assistant.broadcast.tool_call_failed', [
                                    'user_id' => $userId,
                                    'tool_call_id' => $toolCall->id,
                                    'tool_name' => $toolCall->name,
                                    'error' => $e->getMessage(),
                                ]);
                            }

                            continue;
                        }

                        if ($event instanceof ToolResultEvent) {
                            $toolResult = $event->toolResult;

                            $this->toolEventPersister->persistToolResult(
                                assistantMessage: $assistantMessage,
                                toolResult: $toolResult,
                                seenToolResultCallIds: $seenToolResultCallIds,
                                success: $event->success,
                                error: $event->error,
                            );
                            try {
                                broadcast(new TaskAssistantToolResult(
                                    userId: $userId,
                                    toolCallId: $toolResult->toolCallId,
                                    toolName: $toolResult->toolName,
                                    result: '',
                                    success: $event->success,
                                    error: $event->error,
                                ));
                            } catch (\Throwable $e) {
                                Log::warning('task-assistant.broadcast.tool_result_failed', [
                                    'user_id' => $userId,
                                    'tool_call_id' => $toolResult->toolCallId,
                                    'tool_name' => $toolResult->toolName,
                                    'error' => $e->getMessage(),
                                ]);
                            }

                            continue;
                        }

                        $delta = $this->deltaExtractor->extractDelta($event);
                        if ($delta !== null) {
                            $fullText .= $delta;
                            echo $delta;
                            @ob_flush();
                            flush();
                        }
                    }
                } else {
                    $textResponse = $pending->asText();
                    $fullText = (string) ($textResponse->text ?? '');
                    echo $fullText;
                    @ob_flush();
                    flush();
                }

                $assistantMessage->update([
                    'content' => $fullText,
                    'metadata' => array_merge($assistantMessage->metadata ?? [], [
                        'streamed' => true,
                    ]),
                ]);
            } finally {
                $this->clearTaskAssistantContext();
            }
        }, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /**
     * Run the \"choose next task and break into steps\" flow using structured JSON output.
     *
     * @return array{valid: bool, data: array<string, mixed>, errors: array<int, string>, user_message: string}
     */
    public function runTaskChoice(TaskAssistantThread $thread, string $userMessageContent, TaskAssistantTaskChoiceRunner $runner, TaskAssistantIntent $intent = TaskAssistantIntent::TaskPrioritization): array
    {
        $user = $thread->user;

        $userMessage = $thread->messages()->create([
            'role' => MessageRole::User,
            'content' => $userMessageContent,
        ]);

        $assistantMessage = $thread->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => '',
        ]);

        $this->bindTaskAssistantContext($thread->id, $assistantMessage->id);

        try {
            $historyMessages = $this->loadHistoryMessages($thread, $userMessage->id);
            $prismMessages = collect($this->mapToPrismMessages($historyMessages));
            $tools = $this->resolveToolsForIntent($user, $intent);

            $result = $runner->run($thread, $userMessageContent, $prismMessages, $tools);

            $this->flowExecutionEngine->executeStructuredFlow(
                flow: 'task_choice',
                metadataKey: 'task_choice',
                thread: $thread,
                assistantMessage: $assistantMessage,
                generationResult: $result,
                originalUserMessage: $userMessageContent,
                assistantFallbackContent: 'I had trouble understanding that suggestion. You can try asking again or pick a task directly from your list.'
            );

            return [
                'valid' => $result['valid'],
                'data' => $result['data'],
                'errors' => $result['errors'],
                'user_message' => $result['valid']
                    ? 'Here is a structured plan for your next task.'
                    : 'I had trouble understanding that suggestion. You can try asking again or pick a task directly from your list.',
            ];
        } catch (\Throwable $e) {
            Log::error('task-assistant.task-choice.error', [
                'user_id' => $user->id,
                'thread_id' => $thread->id,
                'exception' => $e,
            ]);

            return [
                'valid' => false,
                'data' => [],
                'errors' => ['An unexpected error occurred while generating a structured plan.'],
                'user_message' => 'I had trouble understanding that suggestion. You can try asking again or pick a task directly from your list.',
            ];
        } finally {
            $this->clearTaskAssistantContext();
        }
    }

    /**
     * Run the \"propose a daily schedule\" flow using structured JSON output.
     *
     * @return array{valid: bool, data: array<string, mixed>, errors: array<int, string>, user_message: string}
     */
    public function runDailySchedule(TaskAssistantThread $thread, string $userMessageContent, TaskAssistantIntent $intent = TaskAssistantIntent::TimeManagement): array
    {
        $user = $thread->user;

        $userMessage = $thread->messages()->create([
            'role' => MessageRole::User,
            'content' => $userMessageContent,
        ]);

        $assistantMessage = $thread->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => '',
        ]);

        $this->bindTaskAssistantContext($thread->id, $assistantMessage->id);

        try {
            $historyMessages = $this->loadHistoryMessages($thread, $userMessage->id);
            $prismMessages = collect($this->mapToPrismMessages($historyMessages));
            $tools = $this->resolveToolsForIntent($user, $intent);

            $result = $this->structuredFlowGenerator->generateDailySchedule($thread, $userMessageContent, $prismMessages, $tools);

            $this->flowExecutionEngine->executeStructuredFlow(
                flow: 'daily_schedule',
                metadataKey: 'daily_schedule',
                thread: $thread,
                assistantMessage: $assistantMessage,
                generationResult: $result,
                originalUserMessage: $userMessageContent,
                assistantFallbackContent: 'I had trouble generating a schedule. You can try asking again or sketch one directly on your calendar.'
            );

            return [
                'valid' => $result['valid'],
                'data' => $result['data'],
                'errors' => $result['errors'],
                'user_message' => $result['valid']
                    ? 'Here is a proposed schedule for your day.'
                    : 'I had trouble generating a schedule. You can try asking again or sketch one directly on your calendar.',
            ];
        } catch (\Throwable $e) {
            Log::error('task-assistant.daily-schedule.error', [
                'user_id' => $user->id,
                'thread_id' => $thread->id,
                'exception' => $e,
            ]);

            return [
                'valid' => false,
                'data' => [],
                'errors' => ['An unexpected error occurred while generating a daily schedule.'],
                'user_message' => 'I had trouble generating a schedule. You can try asking again or sketch one directly on your calendar.',
            ];
        } finally {
            $this->clearTaskAssistantContext();
        }
    }

    /**
     * Run the \"study / revision plan\" flow using structured JSON output.
     *
     * @return array{valid: bool, data: array<string, mixed>, errors: array<int, string>, user_message: string}
     */
    public function runStudyPlan(TaskAssistantThread $thread, string $userMessageContent, TaskAssistantIntent $intent = TaskAssistantIntent::StudyPlanning): array
    {
        $user = $thread->user;

        $userMessage = $thread->messages()->create([
            'role' => MessageRole::User,
            'content' => $userMessageContent,
        ]);

        $assistantMessage = $thread->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => '',
        ]);

        $this->bindTaskAssistantContext($thread->id, $assistantMessage->id);

        try {
            $historyMessages = $this->loadHistoryMessages($thread, $userMessage->id);
            $prismMessages = collect($this->mapToPrismMessages($historyMessages));
            $tools = $this->resolveToolsForIntent($user, $intent);

            $result = $this->structuredFlowGenerator->generateStudyPlan($thread, $userMessageContent, $prismMessages, $tools);

            $this->flowExecutionEngine->executeStructuredFlow(
                flow: 'study_plan',
                metadataKey: 'study_plan',
                thread: $thread,
                assistantMessage: $assistantMessage,
                generationResult: $result,
                originalUserMessage: $userMessageContent,
                assistantFallbackContent: 'I had trouble generating a study plan. You can try asking again or sketch a short list directly.'
            );

            return [
                'valid' => $result['valid'],
                'data' => $result['data'],
                'errors' => $result['errors'],
                'user_message' => $result['valid']
                    ? 'Here is a structured study or revision plan.'
                    : 'I had trouble generating a study plan. You can try asking again or sketch a short list directly.',
            ];
        } catch (\Throwable $e) {
            Log::error('task-assistant.study-plan.error', [
                'user_id' => $user->id,
                'thread_id' => $thread->id,
                'exception' => $e,
            ]);

            return [
                'valid' => false,
                'data' => [],
                'errors' => ['An unexpected error occurred while generating a study plan.'],
                'user_message' => 'I had trouble generating a study plan. You can try asking again or sketch a short list directly.',
            ];
        } finally {
            $this->clearTaskAssistantContext();
        }
    }

    /**
     * Run the \"review summary\" flow using structured JSON output.
     *
     * @return array{valid: bool, data: array<string, mixed>, errors: array<int, string>, user_message: string}
     */
    public function runReviewSummary(TaskAssistantThread $thread, string $userMessageContent, TaskAssistantIntent $intent = TaskAssistantIntent::ProgressReview): array
    {
        $user = $thread->user;

        $userMessage = $thread->messages()->create([
            'role' => MessageRole::User,
            'content' => $userMessageContent,
        ]);

        $assistantMessage = $thread->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => '',
        ]);

        $this->bindTaskAssistantContext($thread->id, $assistantMessage->id);

        try {
            $historyMessages = $this->loadHistoryMessages($thread, $userMessage->id);
            $prismMessages = collect($this->mapToPrismMessages($historyMessages));
            $tools = $this->resolveToolsForIntent($user, $intent);

            $result = $this->structuredFlowGenerator->generateReviewSummary($thread, $userMessageContent, $prismMessages, $tools);

            $this->flowExecutionEngine->executeStructuredFlow(
                flow: 'review_summary',
                metadataKey: 'review_summary',
                thread: $thread,
                assistantMessage: $assistantMessage,
                generationResult: $result,
                originalUserMessage: $userMessageContent,
                assistantFallbackContent: 'I had trouble summarizing your work. You can try asking again or review your task list directly.'
            );

            return [
                'valid' => $result['valid'],
                'data' => $result['data'],
                'errors' => $result['errors'],
                'user_message' => $result['valid']
                    ? 'Here is a short review summary of your tasks.'
                    : 'I had trouble summarizing your work. You can try asking again or review your task list directly.',
            ];
        } catch (\Throwable $e) {
            Log::error('task-assistant.review-summary.error', [
                'user_id' => $user->id,
                'thread_id' => $thread->id,
                'exception' => $e,
            ]);

            return [
                'valid' => false,
                'data' => [],
                'errors' => ['An unexpected error occurred while generating a review summary.'],
                'user_message' => 'I had trouble summarizing your work. You can try asking again or review your task list directly.',
            ];
        } finally {
            $this->clearTaskAssistantContext();
        }
    }

    /**
     * PHP-driven mutating flow: let the model suggest a tool-like JSON structure,
     * interpret it in PHP, and execute the corresponding tool safely.
     *
     * @return array{ok: bool, user_message: string, tool?: string, result?: array<string, mixed>, error?: string}
     */
    public function handleMutatingToolSuggestion(TaskAssistantThread $thread, string $userMessageContent): array
    {
        $user = $thread->user;

        $userMessage = $thread->messages()->create([
            'role' => MessageRole::User,
            'content' => $userMessageContent,
        ]);

        $assistantMessage = $thread->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => '',
        ]);

        $this->bindTaskAssistantContext($thread->id, $assistantMessage->id);

        try {
            $historyMessages = $this->loadHistoryMessages($thread, $userMessage->id);
            $prismMessages = collect($this->mapToPrismMessages($historyMessages));
            $prismMessages->push(new UserMessage($userMessageContent));

            $promptData = $this->promptData->forUser($user);
            $promptData['toolManifest'] = [];
            $snapshot = $this->snapshotService->buildForUser($user);
            Log::info('task-assistant.snapshot', [
                'user_id' => $user->id,
                'thread_id' => $thread->id,
                'snapshot' => $snapshot,
            ]);
            $promptData['snapshot'] = $snapshot;

            $timeout = (int) config('prism.request_timeout', 120);

            $schema = \App\Support\LLM\TaskAssistantSchemas::mutatingSuggestionSchema();

            $structuredResponse = Prism::structured()
                ->using(Provider::Ollama, (string) config('task-assistant.model', 'hermes3:3b'))
                ->withSystemPrompt(view('prompts.task-assistant-system', $promptData))
                ->withMessages($prismMessages->all())
                ->withTools([]) // PHP-driven tools: model only suggests, Laravel executes.
                ->withSchema($schema)
                ->withClientOptions(['timeout' => $timeout])
                ->asStructured();

            $payload = $structuredResponse->structured ?? [];
            if (! is_array($payload)) {
                $payload = [];
            }

            // Support both the documented {action, args} shape and tool-like envelopes.
            $rawEnvelope = [
                'tool' => $payload['action'] ?? $payload['tool'] ?? $payload['name'] ?? $payload['function'] ?? null,
                'arguments' => $payload['args'] ?? $payload['arguments'] ?? $payload['parameters'] ?? [],
            ];

            $normalized = $this->toolInterpreter->interpret($rawEnvelope);
            if ($normalized === null) {
                Log::warning('task-assistant.mutating.suggestion_unusable', [
                    'user_id' => $user->id,
                    'thread_id' => $thread->id,
                    'payload' => $payload,
                ]);

                $assistantMessage->update([
                    'content' => 'I was not confident enough about which change to make. Try rephrasing your request or make the change directly.',
                ]);

                return [
                    'ok' => false,
                    'user_message' => 'I was not confident enough about which change to make. Try rephrasing your request or make the change directly.',
                    'error' => 'Unusable tool suggestion payload.',
                ];
            }

            $toolName = $normalized['tool'];
            $arguments = $normalized['arguments'];

            // Only allow tools defined in prism-tools and treat list_tasks as non-destructive but allowed.
            $allowedTools = array_keys(config('prism-tools', []));
            if (! in_array($toolName, $allowedTools, true)) {
                Log::warning('task-assistant.mutating.unknown_tool', [
                    'user_id' => $user->id,
                    'thread_id' => $thread->id,
                    'tool' => $toolName,
                ]);

                $assistantMessage->update([
                    'content' => 'I was not able to match that request to a safe action. Please try again with a clearer request.',
                ]);

                return [
                    'ok' => false,
                    'user_message' => 'I was not able to match that request to a safe action. Please try again with a clearer request.',
                    'tool' => $toolName,
                    'error' => 'Suggested tool is not configured.',
                ];
            }

            $toolClass = $this->toolInterpreter->resolveToolClass($toolName);
            if ($toolClass === null) {
                Log::warning('task-assistant.mutating.unresolvable_tool_class', [
                    'user_id' => $user->id,
                    'thread_id' => $thread->id,
                    'tool' => $toolName,
                ]);

                $assistantMessage->update([
                    'content' => 'I was not able to match that request to a safe action. Please try again with a clearer request.',
                ]);

                return [
                    'ok' => false,
                    'user_message' => 'I was not able to match that request to a safe action. Please try again with a clearer request.',
                    'tool' => $toolName,
                    'error' => 'Tool class not found for suggested tool.',
                ];
            }

            /** @var object $toolInstance */
            $toolInstance = app()->make($toolClass, ['user' => $user]);

            try {
                $toolCallId = bin2hex(random_bytes(8));
                /** @var array<string, true> */
                $seenToolCallIds = [];
                /** @var array<string, true> */
                $seenToolResultCallIds = [];

                $toolCall = new ToolCall(
                    id: $toolCallId,
                    name: $toolName,
                    arguments: $arguments,
                );

                $this->toolEventPersister->persistToolCall(
                    assistantMessage: $assistantMessage,
                    toolCall: $toolCall,
                    seenToolCallIds: $seenToolCallIds
                );

                try {
                    broadcast(new TaskAssistantToolCall(
                        userId: $user->id,
                        toolCallId: $toolCallId,
                        toolName: $toolName,
                        arguments: $arguments,
                    ));
                } catch (\Throwable $e) {
                    Log::warning('task-assistant.broadcast.tool_call_failed', [
                        'user_id' => $user->id,
                        'thread_id' => $thread->id,
                        'tool_call_id' => $toolCallId,
                        'tool_name' => $toolName,
                        'error' => $e->getMessage(),
                    ]);
                }

                $rawResult = $toolInstance($arguments);

                $toolResult = new ToolResult(
                    toolCallId: $toolCallId,
                    toolName: $toolName,
                    args: is_array($arguments) ? $arguments : [],
                    result: $rawResult,
                );

                $this->toolEventPersister->persistToolResult(
                    assistantMessage: $assistantMessage,
                    toolResult: $toolResult,
                    seenToolResultCallIds: $seenToolResultCallIds,
                    success: true,
                    error: null
                );

                try {
                    broadcast(new TaskAssistantToolResult(
                        userId: $user->id,
                        toolCallId: $toolCallId,
                        toolName: $toolName,
                        result: '',
                        success: true,
                        error: null
                    ));
                } catch (\Throwable $e) {
                    Log::warning('task-assistant.broadcast.tool_result_failed', [
                        'user_id' => $user->id,
                        'thread_id' => $thread->id,
                        'tool_call_id' => $toolCallId,
                        'tool_name' => $toolName,
                        'error' => $e->getMessage(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('task-assistant.mutating.tool_execution_failed', [
                    'user_id' => $user->id,
                    'thread_id' => $thread->id,
                    'tool' => $toolName,
                    'exception' => $e,
                ]);

                $toolCallId = $toolCallId ?? bin2hex(random_bytes(8));

                $toolResult = new ToolResult(
                    toolCallId: $toolCallId,
                    toolName: $toolName,
                    args: is_array($arguments) ? $arguments : [],
                    result: null,
                );

                $this->toolEventPersister->persistToolResult(
                    assistantMessage: $assistantMessage,
                    toolResult: $toolResult,
                    seenToolResultCallIds: $seenToolResultCallIds,
                    success: false,
                    error: $e->getMessage()
                );

                broadcast(new TaskAssistantToolResult(
                    userId: $user->id,
                    toolCallId: $toolCallId,
                    toolName: $toolName,
                    result: 'null',
                    success: false,
                    error: $e->getMessage()
                ));

                $assistantMessage->update([
                    'content' => 'I tried to apply that change but something went wrong. No changes were applied.',
                ]);

                return [
                    'ok' => false,
                    'user_message' => 'I tried to apply that change but something went wrong. No changes were applied.',
                    'tool' => $toolName,
                    'error' => 'Tool execution threw an exception.',
                ];
            }

            $decodedResult = null;
            if (is_string($rawResult)) {
                $decodedResult = json_decode($rawResult, true);
            }
            if (! is_array($decodedResult)) {
                $decodedResult = ['raw' => $rawResult];
            }

            $ok = (bool) ($decodedResult['ok'] ?? true);
            $userMessage = (string) ($decodedResult['message'] ?? 'Your request has been applied.');

            $assistantMessage->update([
                'content' => $userMessage,
                'metadata' => array_merge($assistantMessage->metadata ?? [], [
                    'mutating_tool' => $toolName,
                    'mutating_result' => $decodedResult,
                ]),
            ]);

            return [
                'ok' => $ok,
                'user_message' => $userMessage,
                'tool' => $toolName,
                'result' => $decodedResult,
            ];
        } catch (\Throwable $e) {
            Log::error('task-assistant.mutating.error', [
                'user_id' => $user->id,
                'thread_id' => $thread->id,
                'exception' => $e,
            ]);

            $assistantMessage->update([
                'content' => 'I had trouble safely applying that change. No changes were applied.',
            ]);

            return [
                'ok' => false,
                'user_message' => 'I had trouble safely applying that change. No changes were applied.',
                'error' => 'Unexpected error in mutating flow.',
            ];
        } finally {
            $this->clearTaskAssistantContext();
        }
    }

    /**
     * Run the Prism stream and broadcast to Reverb; persist assistant message in callback.
     * Caller must have already created the user message and placeholder assistant message.
     */
    public function broadcastStream(TaskAssistantThread $thread, int $userMessageId, int $assistantMessageId, TaskAssistantIntent $intent = TaskAssistantIntent::ProductivityCoaching): void
    {
        Log::info('task-assistant.broadcastStream.start', [
            'thread_id' => $thread->id,
            'user_message_id' => $userMessageId,
            'assistant_message_id' => $assistantMessageId,
        ]);

        $userMessage = TaskAssistantMessage::query()
            ->where('thread_id', $thread->id)
            ->where('id', $userMessageId)
            ->first();
        $assistantMessage = TaskAssistantMessage::query()
            ->where('thread_id', $thread->id)
            ->where('id', $assistantMessageId)
            ->first();
        if (! $userMessage || ! $assistantMessage) {
            return;
        }

        $user = $thread->user;
        $historyMessages = $this->loadHistoryMessages($thread, $userMessageId);
        $prismMessages = $this->mapToPrismMessages($historyMessages);
        $prismMessages[] = new UserMessage($userMessage->content ?? '');
        $tools = $this->resolveToolsForIntent($user, $intent);
        $promptData = $this->promptData->forUser($user);
        $promptData['toolManifest'] = $this->buildToolManifestFromTools($tools);
        $snapshot = $this->snapshotService->buildForUser($user);
        Log::info('task-assistant.snapshot', [
            'user_id' => $user->id,
            'thread_id' => $thread->id,
            'snapshot' => $snapshot,
        ]);
        $promptData['snapshot'] = $snapshot;
        $timeout = (int) config('prism.request_timeout', 120);
        $channel = new Channel('task-assistant.user.'.$user->id);

        $this->bindTaskAssistantContext($thread->id, $assistantMessage->id);

        try {
            Log::info('task-assistant.broadcastStream.prism_start', [
                'thread_id' => $thread->id,
                'assistant_message_id' => $assistantMessageId,
                'channel' => $channel->name,
            ]);

            $pending = Prism::text()
                ->using(Provider::Ollama, (string) config('task-assistant.model', 'hermes3:3b'))
                ->withSystemPrompt(view('prompts.task-assistant-system', $promptData))
                ->withMessages($prismMessages)
                ->withTools($tools)
                ->withMaxSteps(4)
                ->withClientOptions(['timeout' => $timeout]);
            $this->streamingBroadcaster->streamPrismTextToAssistant(
                pending: $pending,
                userId: $user->id,
                assistantMessage: $assistantMessage,
                persistEveryChars: 400,
                fallbackChunkSize: self::STREAM_CHUNK_SIZE
            );
        } finally {
            Log::info('task-assistant.broadcastStream.end', [
                'thread_id' => $thread->id,
                'assistant_message_id' => $assistantMessageId,
            ]);
            $this->clearTaskAssistantContext();
        }
    }

    /**
     * Process a queued user message and stream the assistant response.
     *
     * All flows update the existing assistant message and broadcast `.json_delta` + `.stream_end`.
     */
    public function processQueuedMessage(TaskAssistantThread $thread, int $userMessageId, int $assistantMessageId, TaskAssistantIntent $intent = TaskAssistantIntent::ProductivityCoaching): void
    {
        $userMessage = TaskAssistantMessage::query()
            ->where('thread_id', $thread->id)
            ->where('id', $userMessageId)
            ->first();
        $assistantMessage = TaskAssistantMessage::query()
            ->where('thread_id', $thread->id)
            ->where('id', $assistantMessageId)
            ->first();

        if (! $userMessage || ! $assistantMessage) {
            return;
        }

        $context = $this->flowPipeline->buildContext($thread, $userMessage, $assistantMessage, $intent);
        $flow = $context->flow;

        Log::info('task-assistant.processQueuedMessage.start', [
            'thread_id' => $thread->id,
            'user_message_id' => $userMessageId,
            'assistant_message_id' => $assistantMessageId,
            'flow' => $flow,
        ]);

        // Strong override: if the user is asking to list tasks, always route to `task_list`
        // even if the intent classifier mapped this request to another flow.
        if ($this->isListTasksRequest((string) ($userMessage->content ?? ''))) {
            $this->bindTaskAssistantContext($thread->id, $assistantMessage->id);

            try {
                $result = $this->runTaskListOnExistingMessages($thread, $userMessage, $assistantMessage);
                $envelope = $this->buildJsonEnvelope(
                    flow: 'task_list',
                    data: $result['data'] ?? [],
                    threadId: $thread->id,
                    assistantMessageId: $assistantMessage->id,
                    ok: (bool) ($result['valid'] ?? false),
                );
                $this->streamFinalAssistantJson($thread->user_id, $assistantMessage, $envelope);

                return;
            } finally {
                $this->clearTaskAssistantContext();
            }
        }

        if ($flow === 'advisory') {
            $this->broadcastStream($thread, $userMessageId, $assistantMessageId, TaskAssistantIntent::ProductivityCoaching);

            return;
        }

        $this->bindTaskAssistantContext($thread->id, $assistantMessage->id);

        try {
            if ($flow === 'mutating') {
                $result = $this->handleMutatingToolSuggestionOnExistingMessages($thread, $userMessage, $assistantMessage);
                $envelope = $this->buildJsonEnvelope(
                    flow: 'mutating',
                    data: $assistantMessage->metadata['mutating_result'] ?? [],
                    threadId: $thread->id,
                    assistantMessageId: $assistantMessage->id,
                    ok: (bool) ($result['ok'] ?? false),
                );
                $this->streamFinalAssistantJson($thread->user_id, $assistantMessage, $envelope);

                return;
            }

            if ($flow === 'task_choice') {
                /** @var TaskAssistantTaskChoiceRunner $runner */
                $runner = app(TaskAssistantTaskChoiceRunner::class);
                $result = $this->runTaskChoiceOnExistingMessages($thread, $userMessage, $assistantMessage, $runner);
                $envelope = $this->buildJsonEnvelope(
                    flow: 'task_choice',
                    data: $result['data'] ?? [],
                    threadId: $thread->id,
                    assistantMessageId: $assistantMessage->id,
                    ok: (bool) ($result['valid'] ?? false),
                );
                $this->streamFinalAssistantJson($thread->user_id, $assistantMessage, $envelope);

                return;
            }

            if ($flow === 'daily_schedule') {
                $result = $this->runDailyScheduleOnExistingMessages($thread, $userMessage, $assistantMessage);
                $envelope = $this->buildJsonEnvelope(
                    flow: 'daily_schedule',
                    data: $result['data'] ?? [],
                    threadId: $thread->id,
                    assistantMessageId: $assistantMessage->id,
                    ok: (bool) ($result['valid'] ?? false),
                );
                $this->streamFinalAssistantJson($thread->user_id, $assistantMessage, $envelope);

                return;
            }

            if ($flow === 'study_plan') {
                $result = $this->runStudyPlanOnExistingMessages($thread, $userMessage, $assistantMessage);
                $envelope = $this->buildJsonEnvelope(
                    flow: 'study_plan',
                    data: $result['data'] ?? [],
                    threadId: $thread->id,
                    assistantMessageId: $assistantMessage->id,
                    ok: (bool) ($result['valid'] ?? false),
                );
                $this->streamFinalAssistantJson($thread->user_id, $assistantMessage, $envelope);

                return;
            }

            if ($flow === 'review_summary') {
                $result = $this->runReviewSummaryOnExistingMessages($thread, $userMessage, $assistantMessage);
                $envelope = $this->buildJsonEnvelope(
                    flow: 'review_summary',
                    data: $result['data'] ?? [],
                    threadId: $thread->id,
                    assistantMessageId: $assistantMessage->id,
                    ok: (bool) ($result['valid'] ?? false),
                );
                $this->streamFinalAssistantJson($thread->user_id, $assistantMessage, $envelope);

                return;
            }

            // fallback to advisory if unknown
            $this->broadcastStream($thread, $userMessageId, $assistantMessageId, TaskAssistantIntent::ProductivityCoaching);
        } finally {
            $this->clearTaskAssistantContext();
        }
    }

    /**
     * Broadcast a final assistant message as `.json_delta` chunks, then `.stream_end`.
     * Note: The assistantMessage->content should already be formatted by ResponseProcessor.
     */
    private function streamFinalAssistantJson(int $userId, TaskAssistantMessage $assistantMessage, array $envelope): void
    {
        $this->streamingBroadcaster->streamFinalAssistantJson(
            userId: $userId,
            assistantMessage: $assistantMessage,
            envelope: $envelope,
            chunkSize: self::STREAM_CHUNK_SIZE
        );
    }

    /**
     * Run task-choice flow using existing message rows (async job path).
     */
    private function runTaskChoiceOnExistingMessages(
        TaskAssistantThread $thread,
        TaskAssistantMessage $userMessage,
        TaskAssistantMessage $assistantMessage,
        TaskAssistantTaskChoiceRunner $runner
    ): array {
        $user = $thread->user;
        $historyMessages = $this->loadHistoryMessages($thread, $userMessage->id);
        $prismMessages = collect($this->mapToPrismMessages($historyMessages));
        $tools = $this->resolveToolsForIntent($user, TaskAssistantIntent::TaskPrioritization);
        $result = $runner->run($thread, (string) $userMessage->content, $prismMessages, $tools);
        $execution = $this->flowExecutionEngine->executeStructuredFlow(
            flow: 'task_choice',
            metadataKey: 'task_choice',
            thread: $thread,
            assistantMessage: $assistantMessage,
            generationResult: $result,
            originalUserMessage: (string) ($userMessage->content ?? ''),
            assistantFallbackContent: 'I had trouble understanding that suggestion. You can try asking again or pick a task directly from your list.'
        );

        return [
            'valid' => $execution['final_valid'],
            'data' => $execution['structured_data'],
            'errors' => $execution['merged_errors'],
            'user_message' => $execution['assistant_content'],
        ];
    }

    private function runDailyScheduleOnExistingMessages(
        TaskAssistantThread $thread,
        TaskAssistantMessage $userMessage,
        TaskAssistantMessage $assistantMessage
    ): array {
        $user = $thread->user;
        $historyMessages = $this->loadHistoryMessages($thread, $userMessage->id);
        $prismMessages = collect($this->mapToPrismMessages($historyMessages));
        $tools = $this->resolveToolsForIntent($user, TaskAssistantIntent::TimeManagement);
        $result = $this->structuredFlowGenerator->generateDailySchedule($thread, (string) $userMessage->content, $prismMessages, $tools);
        $execution = $this->flowExecutionEngine->executeStructuredFlow(
            flow: 'daily_schedule',
            metadataKey: 'daily_schedule',
            thread: $thread,
            assistantMessage: $assistantMessage,
            generationResult: $result,
            originalUserMessage: (string) ($userMessage->content ?? ''),
            assistantFallbackContent: 'I had trouble generating a schedule. You can try asking again or sketch one directly on your calendar.'
        );

        return [
            'valid' => $execution['final_valid'],
            'data' => $execution['structured_data'],
            'errors' => $execution['merged_errors'],
            'user_message' => $execution['assistant_content'],
        ];
    }

    private function runStudyPlanOnExistingMessages(
        TaskAssistantThread $thread,
        TaskAssistantMessage $userMessage,
        TaskAssistantMessage $assistantMessage
    ): array {
        $user = $thread->user;
        $historyMessages = $this->loadHistoryMessages($thread, $userMessage->id);
        $prismMessages = collect($this->mapToPrismMessages($historyMessages));
        $tools = $this->resolveToolsForIntent($user, TaskAssistantIntent::StudyPlanning);
        $result = $this->structuredFlowGenerator->generateStudyPlan($thread, (string) $userMessage->content, $prismMessages, $tools);
        $execution = $this->flowExecutionEngine->executeStructuredFlow(
            flow: 'study_plan',
            metadataKey: 'study_plan',
            thread: $thread,
            assistantMessage: $assistantMessage,
            generationResult: $result,
            originalUserMessage: (string) ($userMessage->content ?? ''),
            assistantFallbackContent: 'I had trouble generating a study plan. You can try asking again or sketch a short list directly.'
        );

        return [
            'valid' => $execution['final_valid'],
            'data' => $execution['structured_data'],
            'errors' => $execution['merged_errors'],
            'user_message' => $execution['assistant_content'],
        ];
    }

    private function runReviewSummaryOnExistingMessages(
        TaskAssistantThread $thread,
        TaskAssistantMessage $userMessage,
        TaskAssistantMessage $assistantMessage
    ): array {
        $user = $thread->user;
        $historyMessages = $this->loadHistoryMessages($thread, $userMessage->id);
        $prismMessages = collect($this->mapToPrismMessages($historyMessages));
        $tools = $this->resolveToolsForIntent($user, TaskAssistantIntent::ProgressReview);
        $result = $this->structuredFlowGenerator->generateReviewSummary($thread, (string) $userMessage->content, $prismMessages, $tools);
        $execution = $this->flowExecutionEngine->executeStructuredFlow(
            flow: 'review_summary',
            metadataKey: 'review_summary',
            thread: $thread,
            assistantMessage: $assistantMessage,
            generationResult: $result,
            originalUserMessage: (string) ($userMessage->content ?? ''),
            assistantFallbackContent: 'I had trouble summarizing your work. You can try asking again or review your task list directly.'
        );

        return [
            'valid' => $execution['final_valid'],
            'data' => $execution['structured_data'],
            'errors' => $execution['merged_errors'],
            'user_message' => $execution['assistant_content'],
        ];
    }

    /**
     * Detect “list tasks” style user requests so we can route them into a deterministic task_list flow.
     */
    private function isListTasksRequest(string $content): bool
    {
        $normalized = mb_strtolower(trim($content));

        if ($normalized === '') {
            return false;
        }

        // Allow prompts that contain the tool key directly (ex: "list_tasks").
        if (str_contains($normalized, 'list_tasks')) {
            return true;
        }

        // If the user is asking the model to choose what to do first/next, this is *not* a listing request.
        // Example: "which task should i do first" -> should route to `task_choice`.
        if (preg_match('/\b(choose|help me choose|pick|which|what)\b.*\b(task|tasks|to-?dos|todos)\b.*\b(should\b.*\b(do|work)|do\s*first|do\s*next|work\s*on\s*next|next\s*task)\b/i', $normalized) === 1) {
            return false;
        }

        $hasTaskNoun = preg_match('/\b(task|tasks|to-?do|todos)\b/i', $normalized) === 1;
        $hasListVerb = preg_match('/\b(list|show|get|display|give)\b/i', $normalized) === 1;

        // "my tasks" / "what are my tasks" without an explicit list verb.
        $hasQuestionStyle = preg_match('/\b(what|which)\b.*\b(task|tasks)\b/i', $normalized) === 1;

        return ($hasTaskNoun && $hasListVerb) || $hasQuestionStyle;
    }

    private function extractRequestedTaskCount(string $content, int $default = 5): int
    {
        $normalized = mb_strtolower($content);

        $maxItems = 20;
        $count = null;

        $patterns = [
            '/\btop\s+(\d+)\b/',
            '/\bfirst\s+(\d+)\b/',
            '/\bonly\s+(\d+)\b/',
            '/\bat\s+most\s+(\d+)\b/',
            '/\bup\s+to\s+(\d+)\b/',
            '/\bupto\s+(\d+)\b/',
            '/\bshow\s+(\d+)\s+task(?:s)?\b/',
            '/\blimit\s+(\d+)\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches) === 1) {
                $count = (int) ($matches[1] ?? null);
                break;
            }
        }

        if ($count === null || $count < 1) {
            return $default;
        }

        return max(1, min($count, $maxItems));
    }

    /**
     * @param  array<int, array<string, mixed>>  $tasks
     * @return array<int, array<string, mixed>>
     */
    private function mapListTasksToolResultToSnapshotTasks(array $tasks): array
    {
        $out = [];

        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }

            $id = (int) ($task['id'] ?? 0);
            $title = (string) ($task['title'] ?? '');

            if ($id <= 0 || trim($title) === '') {
                continue;
            }

            $out[] = [
                'id' => $id,
                'title' => $title,
                'subject_name' => $task['subject_name'] ?? null,
                'teacher_name' => $task['teacher_name'] ?? null,
                'tags' => is_array($task['tags'] ?? null) ? $task['tags'] : [],
                'status' => $task['status'] ?? null,
                'priority' => $task['priority'] ?? null,
                'ends_at' => is_string($task['ends_at'] ?? null)
                    ? (string) $task['ends_at']
                    : (is_string($task['end_datetime'] ?? null) ? (string) $task['end_datetime'] : null),
                'project_id' => isset($task['project_id']) && is_numeric($task['project_id']) ? (int) $task['project_id'] : null,
                'event_id' => isset($task['event_id']) && is_numeric($task['event_id']) ? (int) $task['event_id'] : null,
                'duration_minutes' => is_numeric($task['duration_minutes'] ?? null) ? (int) $task['duration_minutes'] : 0,
            ];
        }

        return $out;
    }

    /**
     * Rank tasks deterministically (most urgent first) and return the schema-compatible items.
     *
     * @param  array<int, array<string, mixed>>  $snapshotTasks
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function rankTopTasks(array $snapshotTasks, int $limit, array $context): array
    {
        $timezone = (string) config('app.timezone', 'UTC');

        $snapshot = [
            'today' => date('Y-m-d'),
            'timezone' => $timezone,
            'tasks' => $snapshotTasks,
            'events' => [],
            'projects' => [],
        ];

        $ranked = $this->prioritizationService->prioritizeFocus($snapshot, $context);
        $taskCandidates = array_values(array_filter(
            $ranked,
            fn (array $c): bool => ($c['type'] ?? null) === 'task'
        ));

        $top = array_slice($taskCandidates, 0, $limit);

        $items = [];
        foreach ($top as $candidate) {
            $raw = (array) ($candidate['raw'] ?? []);

            $dueDate = is_string($raw['ends_at'] ?? null)
                ? (string) $raw['ends_at']
                : (is_string($raw['end_datetime'] ?? null) ? (string) $raw['end_datetime'] : null);
            $priority = is_string($raw['priority'] ?? null) ? (string) $raw['priority'] : null;
            $reason = is_string($candidate['reasoning'] ?? null) ? (string) $candidate['reasoning'] : null;
            $title = (string) ($candidate['title'] ?? '');

            $items[] = [
                'task_id' => (int) ($candidate['id'] ?? 0),
                'title' => $title,
                'due_date' => $dueDate,
                'priority' => $priority,
                'reason' => ($reason !== null && trim($reason) !== '') ? $reason : null,
                'next_steps' => $this->buildTaskListNextSteps($title, $dueDate, $priority),
            ];
        }

        return $items;
    }

    /**
     * Build deterministic, per-task next steps from due date + priority.
     *
     * @return array<int, string>
     */
    private function buildTaskListNextSteps(string $title, ?string $dueDate, ?string $priority): array
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $now = new \DateTimeImmutable('now', new \DateTimeZone($timezone));

        $priorityNorm = strtolower(trim((string) ($priority ?? 'medium')));
        $priorityNorm = in_array($priorityNorm, ['urgent', 'high', 'medium', 'low'], true)
            ? $priorityNorm
            : 'medium';

        $today = $now->format('Y-m-d');
        $tomorrow = $now->modify('+1 day')->format('Y-m-d');

        $dueCategory = 'unknown';
        if (is_string($dueDate) && trim($dueDate) !== '') {
            try {
                $dueDt = new \DateTimeImmutable($dueDate);
                if ($dueDt < $now) {
                    $dueCategory = 'overdue';
                } else {
                    $dueLocalDate = $dueDt->setTimezone($now->getTimezone())->format('Y-m-d');
                    if ($dueLocalDate === $today) {
                        $dueCategory = 'today';
                    } elseif ($dueLocalDate === $tomorrow) {
                        $dueCategory = 'tomorrow';
                    } else {
                        $nowMidnight = new \DateTimeImmutable($today.' 00:00:00', $now->getTimezone());
                        $dueMidnight = new \DateTimeImmutable($dueLocalDate.' 00:00:00', $now->getTimezone());
                        $daysUntil = (int) $nowMidnight->diff($dueMidnight)->days;

                        $dueCategory = match (true) {
                            $daysUntil <= 7 => 'this_week',
                            default => 'later',
                        };
                    }
                }
            } catch (\Throwable) {
                $dueCategory = 'unknown';
            }
        }

        $title = trim($title) !== '' ? $title : 'your task';

        return match ($dueCategory) {
            'overdue' => [
                $priorityNorm === 'urgent' || $priorityNorm === 'high'
                    ? 'Start immediately with the first 20-30 minutes on "'.$title.'".'
                    : 'Start with a small first step (20-30 minutes) on "'.$title.'".',
                'Break it into 2-3 smallest next subtasks and begin the earliest one.',
                'When you finish, write a quick update and reschedule the remaining work.',
            ],
            'today' => [
                'Quick setup: gather everything you need to begin "'.$title.'".',
                $priorityNorm === 'urgent' || $priorityNorm === 'high'
                    ? 'Block 30-45 minutes for the most urgent subtask and begin now.'
                    : 'Block 25-40 minutes for the most urgent subtask and begin.',
                'End with a 2-minute check: what’s done, and what’s the next deliverable?',
            ],
            'tomorrow' => [
                'Define a small deliverable for "'.$title.'" that you can finish tomorrow.',
                'Prep resources and outline the exact first steps you’ll start with.',
                'Choose a start time tomorrow and set a short timer.',
            ],
            'this_week' => [
                'List the key milestones for "'.$title.'" and pick the one closest to the deadline.',
                'Schedule the first work block before the due date.',
                'Create a short checklist for what “done” looks like.',
            ],
            'later' => [
                'Outline milestones for "'.$title.'".',
                'Schedule the first session before the due date and protect that time.',
                'Draft a checklist of next actions so starting feels easier.',
            ],
            default => [
                'Review what "'.$title.'" requires and identify the smallest actionable part.',
                'Start with a 15-30 minute burst and complete that first micro-step.',
                'Afterward, decide the next best action and set a follow-up time.',
            ],
        };
    }

    /**
     * Deterministic “list tasks” flow that:
     * - calls `list_tasks` via Prism tool calling
     * - ranks returned tasks in PHP using the existing prioritization service
     * - returns schema-validated structured output.
     *
     * @return array{valid: bool, data: array<string, mixed>, errors: array<int, string>, user_message: string}
     */
    private function runTaskListOnExistingMessages(
        TaskAssistantThread $thread,
        TaskAssistantMessage $userMessage,
        TaskAssistantMessage $assistantMessage
    ): array {
        $user = $thread->user;
        $userMessageContent = (string) ($userMessage->content ?? '');

        $historyMessages = $this->loadHistoryMessages($thread, $userMessage->id);
        $prismMessages = collect($this->mapToPrismMessages($historyMessages));
        $prismMessages->push(new UserMessage($userMessageContent));

        // Extract deterministic context (priority/time) from the user prompt.
        $context = $this->constraintsExtractor->extract($userMessageContent);

        $limitUsed = $this->extractRequestedTaskCount($userMessageContent, 5);
        $toolLimit = 100; // keep enough candidates to pick top-N reliably

        $tools = $this->resolveReadOnlyTools($user);

        $promptData = $this->promptData->forUser($user);
        $promptData['toolManifest'] = $this->buildToolManifestFromTools($tools);
        $snapshot = $this->snapshotService->buildForUser($user, $toolLimit);
        $promptData['snapshot'] = $snapshot;

        $timeout = (int) config('prism.request_timeout', 120);
        $schema1 = \App\Support\LLM\TaskAssistantSchemas::taskListToolCallSchema();

        // Strong instruction: we rely on toolResults; structured output is ignored.
        $prismMessages->push(new UserMessage(
            'Tool call requirement: call the `list_tasks` tool and fetch tasks with limit='.$toolLimit.'. '.
            'After the tool runs, you may return any JSON that matches the schema.'
        ));

        $structuredResponse1 = Prism::structured()
            ->using(Provider::Ollama, (string) config('task-assistant.model', 'hermes3:3b'))
            ->withSystemPrompt(view('prompts.task-assistant-system', $promptData))
            ->withMessages($prismMessages->all())
            ->withTools($tools)
            ->withSchema($schema1)
            ->withClientOptions(['timeout' => $timeout])
            ->asStructured();

        $toolCalls = $structuredResponse1->toolCalls ?? [];
        $toolResults = $structuredResponse1->toolResults ?? [];

        /** @var array<int, array<string, mixed>> $toolTasks */
        $toolTasks = [];
        foreach ($toolResults as $toolResult) {
            if (! $toolResult instanceof ToolResult) {
                continue;
            }

            if ($toolResult->toolName !== 'list_tasks') {
                continue;
            }

            $result = $toolResult->result;
            if (is_string($result)) {
                $decoded = json_decode($result, true);
                $result = is_array($decoded) ? $decoded : [];
            }

            if (! is_array($result)) {
                continue;
            }

            $tasks = $result['tasks'] ?? [];
            if (is_array($tasks)) {
                $toolTasks = array_merge($toolTasks, $tasks);
            }
        }

        if ($toolTasks === []) {
            // Prism didn't execute (or didn't return typed ToolResult objects).
            // To make listing reliable, execute the read-only `list_tasks` tool directly
            // and persist the call+result so the history replay sees it too.
            $listTasksTool = null;
            foreach ($tools as $tool) {
                if ($tool->name() === 'list_tasks') {
                    $listTasksTool = $tool;
                    break;
                }
            }

            if ($listTasksTool !== null) {
                try {
                    $toolCallId = bin2hex(random_bytes(8));
                    $arguments = ['limit' => $toolLimit];

                    /** @var callable $listTasksToolCallable */
                    $listTasksToolCallable = $listTasksTool;
                    $rawResult = $listTasksToolCallable($arguments);
                    $rawResult = is_string($rawResult) ? $rawResult : json_encode($rawResult);

                    $toolCall = new ToolCall(
                        id: $toolCallId,
                        name: 'list_tasks',
                        arguments: $arguments,
                        resultId: null,
                        reasoningId: null,
                        reasoningSummary: null,
                    );

                    $toolResult = new ToolResult(
                        toolCallId: $toolCallId,
                        toolName: 'list_tasks',
                        args: $arguments,
                        result: $rawResult,
                    );

                    $toolCalls = [$toolCall];
                    $toolResults = [$toolResult];

                    $decoded = json_decode($rawResult, true);
                    $toolTasks = is_array($decoded) && isset($decoded['tasks']) && is_array($decoded['tasks'])
                        ? $decoded['tasks']
                        : [];
                } catch (\Throwable) {
                    $toolCalls = [];
                    $toolResults = [];
                    $toolTasks = [];
                }
            }

            if ($toolTasks === []) {
                // Last-resort fallback if the tool execution failed.
                $toolTasks = (is_array($snapshot['tasks'] ?? null) ? $snapshot['tasks'] : []);
            }
        }

        $mappedTasks = $this->mapListTasksToolResultToSnapshotTasks($toolTasks);
        if ($mappedTasks === []) {
            $mappedTasks = [];
        }

        $rankedItems = $this->rankTopTasks($mappedTasks, $limitUsed, $context);

        $returnedCount = count($rankedItems);

        $rankedPayload = [
            'summary' => 'Top tasks based on urgency and priority.',
            // Ensure the reported limit matches what we actually returned.
            'limit_used' => $returnedCount,
            'items' => $rankedItems,
        ];

        // Deterministic items: always trust the PHP-ranked `$rankedItems`.
        $payload2 = $rankedPayload;

        $generationValid = is_array($payload2['items'] ?? null) && ($payload2['items'] ?? []) !== [];

        $result = $this->flowExecutionEngine->executeStructuredFlow(
            flow: 'task_list',
            metadataKey: 'task_list',
            thread: $thread,
            assistantMessage: $assistantMessage,
            generationResult: [
                'valid' => $generationValid,
                'data' => $payload2,
                'errors' => [],
                'tool_calls' => $toolCalls,
                'tool_results' => $toolResults,
            ],
            originalUserMessage: $userMessageContent,
            assistantFallbackContent: 'I had trouble listing your tasks right now. Please try again.',
        );

        return [
            'valid' => $result['final_valid'],
            'data' => $result['structured_data'],
            'errors' => $result['merged_errors'],
            'user_message' => $result['assistant_content'],
        ];
    }

    /**
     * Mutating flow variant that reuses existing user+assistant messages.
     *
     * @return array{ok: bool, user_message: string}
     */
    private function handleMutatingToolSuggestionOnExistingMessages(
        TaskAssistantThread $thread,
        TaskAssistantMessage $userMessage,
        TaskAssistantMessage $assistantMessage
    ): array {
        $user = $thread->user;

        $historyMessages = $this->loadHistoryMessages($thread, $userMessage->id);
        $prismMessages = collect($this->mapToPrismMessages($historyMessages));
        $prismMessages->push(new UserMessage((string) $userMessage->content));

        $promptData = $this->promptData->forUser($user);
        $promptData['toolManifest'] = [];
        $snapshot = $this->snapshotService->buildForUser($user);
        $promptData['snapshot'] = $snapshot;
        $timeout = (int) config('prism.request_timeout', 120);
        $schema = \App\Support\LLM\TaskAssistantSchemas::mutatingSuggestionSchema();

        $structuredResponse = Prism::structured()
            ->using(Provider::Ollama, (string) config('task-assistant.model', 'hermes3:3b'))
            ->withSystemPrompt(view('prompts.task-assistant-system', $promptData))
            ->withMessages($prismMessages->all())
            ->withTools([])
            ->withSchema($schema)
            ->withClientOptions(['timeout' => $timeout])
            ->asStructured();

        $payload = $structuredResponse->structured ?? [];
        if (! is_array($payload)) {
            $payload = [];
        }

        $rawEnvelope = [
            'tool' => $payload['action'] ?? $payload['tool'] ?? $payload['name'] ?? $payload['function'] ?? null,
            'arguments' => $payload['args'] ?? $payload['arguments'] ?? $payload['parameters'] ?? [],
        ];

        $normalized = $this->toolInterpreter->interpret($rawEnvelope);
        if ($normalized === null) {
            $assistantMessage->update([
                'content' => 'I was not confident enough about which change to make. Try rephrasing your request or make the change directly.',
            ]);

            return [
                'ok' => false,
                'user_message' => (string) $assistantMessage->content,
            ];
        }

        $toolName = $normalized['tool'];
        $arguments = $normalized['arguments'];
        $allowedTools = array_keys(config('prism-tools', []));

        if (! in_array($toolName, $allowedTools, true)) {
            $assistantMessage->update([
                'content' => 'I was not able to match that request to a safe action. Please try again with a clearer request.',
            ]);

            return [
                'ok' => false,
                'user_message' => (string) $assistantMessage->content,
            ];
        }

        $toolClass = $this->toolInterpreter->resolveToolClass($toolName);
        if ($toolClass === null) {
            $assistantMessage->update([
                'content' => 'I was not able to match that request to a safe action. Please try again with a clearer request.',
            ]);

            return [
                'ok' => false,
                'user_message' => (string) $assistantMessage->content,
            ];
        }

        /** @var object $toolInstance */
        $toolInstance = app()->make($toolClass, ['user' => $user]);

        $toolCallId = null;
        /** @var array<string, true> */
        $seenToolCallIds = [];
        /** @var array<string, true> */
        $seenToolResultCallIds = [];

        try {
            $toolCallId = bin2hex(random_bytes(8));

            $toolCall = new ToolCall(
                id: $toolCallId,
                name: $toolName,
                arguments: $arguments,
            );

            $this->toolEventPersister->persistToolCall(
                assistantMessage: $assistantMessage,
                toolCall: $toolCall,
                seenToolCallIds: $seenToolCallIds
            );

            try {
                broadcast(new TaskAssistantToolCall(
                    userId: $user->id,
                    toolCallId: $toolCallId,
                    toolName: $toolName,
                    arguments: $arguments,
                ));
            } catch (\Throwable $e) {
                Log::warning('task-assistant.broadcast.tool_call_failed', [
                    'user_id' => $user->id,
                    'thread_id' => $thread->id,
                    'tool_call_id' => $toolCallId,
                    'tool_name' => $toolName,
                    'error' => $e->getMessage(),
                ]);
            }

            $rawResult = $toolInstance($arguments);

            $toolResult = new ToolResult(
                toolCallId: $toolCallId,
                toolName: $toolName,
                args: is_array($arguments) ? $arguments : [],
                result: $rawResult,
            );

            $this->toolEventPersister->persistToolResult(
                assistantMessage: $assistantMessage,
                toolResult: $toolResult,
                seenToolResultCallIds: $seenToolResultCallIds,
                success: true,
                error: null
            );
            try {
                broadcast(new TaskAssistantToolResult(
                    userId: $user->id,
                    toolCallId: $toolCallId,
                    toolName: $toolName,
                    result: '',
                    success: true,
                    error: null
                ));
            } catch (\Throwable $e) {
                Log::warning('task-assistant.broadcast.tool_result_failed', [
                    'user_id' => $user->id,
                    'thread_id' => $thread->id,
                    'tool_call_id' => $toolCallId,
                    'tool_name' => $toolName,
                    'error' => $e->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('task-assistant.mutating.tool_execution_failed', [
                'user_id' => $user->id,
                'thread_id' => $thread->id,
                'tool' => $toolName,
                'exception' => $e,
            ]);

            $toolCallId = $toolCallId ?? bin2hex(random_bytes(8));

            $toolResult = new ToolResult(
                toolCallId: $toolCallId,
                toolName: $toolName,
                args: is_array($arguments) ? $arguments : [],
                result: null,
            );

            $this->toolEventPersister->persistToolResult(
                assistantMessage: $assistantMessage,
                toolResult: $toolResult,
                seenToolResultCallIds: $seenToolResultCallIds,
                success: false,
                error: $e->getMessage()
            );

            try {
                broadcast(new TaskAssistantToolResult(
                    userId: $user->id,
                    toolCallId: $toolCallId,
                    toolName: $toolName,
                    result: '',
                    success: false,
                    error: $e->getMessage(),
                ));
            } catch (\Throwable $e2) {
                Log::warning('task-assistant.broadcast.tool_result_failed', [
                    'user_id' => $user->id,
                    'thread_id' => $thread->id,
                    'tool_call_id' => $toolCallId,
                    'tool_name' => $toolName,
                    'error' => $e2->getMessage(),
                ]);
            }

            $assistantMessage->update([
                'content' => 'I tried to apply that change but something went wrong. No changes were applied.',
            ]);

            return [
                'ok' => false,
                'user_message' => (string) $assistantMessage->content,
            ];
        }

        $decodedResult = null;
        if (is_string($rawResult)) {
            $decodedResult = json_decode($rawResult, true);
        }
        if (! is_array($decodedResult)) {
            $decodedResult = ['raw' => $rawResult];
        }

        $ok = (bool) ($decodedResult['ok'] ?? true);
        $userFacing = (string) ($decodedResult['message'] ?? 'Your request has been applied.');

        $assistantMessage->update([
            'content' => $userFacing,
            'metadata' => array_merge($assistantMessage->metadata ?? [], [
                'mutating_tool' => $toolName,
                'mutating_result' => $decodedResult,
            ]),
        ]);

        return [
            'ok' => $ok,
            'user_message' => $userFacing,
        ];
    }

    /**
     * Load conversation history (messages before the given message id), bounded by MESSAGE_LIMIT.
     *
     * @return Collection<int, TaskAssistantMessage>
     */
    private function loadHistoryMessages(TaskAssistantThread $thread, int $beforeMessageId): Collection
    {
        return $thread->messages()
            ->where('id', '<', $beforeMessageId)
            ->orderBy('id')
            ->limit(self::MESSAGE_LIMIT)
            ->get()
            ->values();
    }

    /**
     * Map stored messages to Prism Message objects (UserMessage, AssistantMessage, ToolResultMessage).
     *
     * @param  Collection<int, TaskAssistantMessage>  $messages
     * @return array<int, UserMessage|AssistantMessage|ToolResultMessage>
     */
    private function mapToPrismMessages(Collection $messages): array
    {
        $out = [];
        $i = 0;
        $messagesCount = $messages->count();

        while ($i < $messagesCount) {
            $msg = $messages->get($i);
            if ($msg->role === MessageRole::User) {
                $out[] = new UserMessage($msg->content ?? '');
                $i++;

                continue;
            }
            if ($msg->role === MessageRole::Assistant) {
                $toolCalls = $this->parseToolCalls($msg->tool_calls);
                $out[] = new AssistantMessage($msg->content ?? '', $toolCalls);
                $i++;
                $toolResults = [];
                while ($i < $messagesCount && $messages->get($i)->role === MessageRole::Tool) {
                    $toolMsg = $messages->get($i);
                    $meta = $toolMsg->metadata ?? [];
                    $toolResult = $toolMsg->content;
                    if (is_string($toolResult)) {
                        $decoded = json_decode($toolResult, true);
                        $toolResult = is_array($decoded) ? $decoded : ['raw' => $toolResult];
                    }
                    if (! is_array($toolResult)) {
                        $toolResult = ['raw' => $toolResult];
                    }
                    $toolResults[] = new ToolResult(
                        toolCallId: (string) ($meta['tool_call_id'] ?? ''),
                        toolName: (string) ($meta['tool_name'] ?? ''),
                        args: (array) ($meta['args'] ?? []),
                        result: $toolResult
                    );
                    $i++;
                }
                if ($toolResults !== []) {
                    $out[] = new ToolResultMessage($toolResults);
                }

                continue;
            }
            if ($msg->role === MessageRole::System) {
                $i++;

                continue;
            }
            $i++;
        }

        return $out;
    }

    /**
     * Build Prism ToolCall array from stored tool_calls (id, name, arguments).
     *
     * @param  array<int, array{id?: string, name?: string, arguments?: string|array}>|null  $toolCalls
     * @return ToolCall[]
     */
    private function parseToolCalls(?array $toolCalls): array
    {
        if ($toolCalls === null || $toolCalls === []) {
            return [];
        }
        $result = [];
        foreach ($toolCalls as $tc) {
            $id = (string) ($tc['id'] ?? '');
            $name = (string) ($tc['name'] ?? '');
            $args = $tc['arguments'] ?? [];
            if (is_string($args)) {
                // keep as string for Prism
            } else {
                $args = is_array($args) ? $args : [];
            }
            $result[] = new ToolCall(
                id: $id,
                name: $name,
                arguments: $args,
                resultId: null,
                reasoningId: null,
                reasoningSummary: null
            );
        }

        return $result;
    }

    private function bindTaskAssistantContext(int $threadId, int $messageId): void
    {
        app()->instance('task_assistant.thread_id', $threadId);
        app()->instance('task_assistant.message_id', $messageId);
    }

    private function clearTaskAssistantContext(): void
    {
        app()->forgetInstance('task_assistant.thread_id');
        app()->forgetInstance('task_assistant.message_id');
    }

    /**
     * Resolve tool instances for the given user (same pattern as TaskAssistantPromptData).
     *
     * @return Tool[]
     */
    private function resolveTools(User $user): array
    {
        $tools = [];
        foreach (config('prism-tools', []) as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }
            $tools[] = app()->make($class, ['user' => $user]);
        }

        return $tools;
    }

    /**
     * Resolve tools based on the current assistant intent.
     *
     * @return Tool[]
     */
    private function resolveToolsForIntent(User $user, TaskAssistantIntent $intent): array
    {
        if ($intent === TaskAssistantIntent::TaskManagement) {
            return $this->resolveTools($user);
        }

        if ($intent === TaskAssistantIntent::ProgressReview || $intent === TaskAssistantIntent::TimeManagement || $intent === TaskAssistantIntent::StudyPlanning) {
            return $this->resolveReadOnlyTools($user);
        }

        if ($intent === TaskAssistantIntent::TaskPrioritization || $intent === TaskAssistantIntent::ProductivityCoaching) {
            return $this->resolveReadOnlyTools($user);
        }

        return $this->resolveReadOnlyTools($user);
    }

    /**
     * @return Tool[]
     */
    private function resolveReadOnlyTools(User $user): array
    {
        $tools = [];
        $config = config('prism-tools', []);
        foreach (self::READ_ONLY_TOOL_KEYS as $key) {
            $class = $config[$key] ?? null;
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }
            $tools[] = app()->make($class, ['user' => $user]);
        }

        return $tools;
    }

    /**
     * @param  Tool[]  $tools
     * @return list<array{name: string, description: string}>
     */
    private function buildToolManifestFromTools(array $tools): array
    {
        $out = [];
        foreach ($tools as $tool) {
            $out[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{type: 'task_assistant', ok: bool, flow: string, data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function buildJsonEnvelope(string $flow, array $data, int $threadId, int $assistantMessageId, bool $ok = true): array
    {
        return [
            'type' => 'task_assistant',
            'ok' => $ok,
            'flow' => $flow,
            'data' => $data,
            'meta' => [
                'thread_id' => $threadId,
                'assistant_message_id' => $assistantMessageId,
            ],
        ];
    }
}
