<?php

namespace App\Services\LLM\TaskAssistant;

use App\Enums\MessageRole;
use App\Enums\TaskAssistantIntent;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
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
    ) {}

    /**
     * Run a single request with tools and streaming; persist user and assistant messages.
     */
    public function streamResponse(TaskAssistantThread $thread, string $userMessageContent, TaskAssistantIntent $intent = TaskAssistantIntent::ProductivityCoaching): StreamedResponse
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

        return response()->stream(function () use ($assistantMessage, $prismMessages, $tools, $promptData, $timeout): void {
            try {
                $pending = Prism::text()
                    ->using(Provider::Ollama, (string) config('task-assistant.model', 'hermes3:3b'))
                    ->withSystemPrompt(view('prompts.task-assistant-system', $promptData))
                    ->withMessages($prismMessages)
                    ->withTools($tools)
                    ->withMaxSteps(4)
                    ->withClientOptions(['timeout' => $timeout]);

                $fullText = '';
                if (method_exists($pending, 'asStream')) {
                    foreach ($pending->asStream() as $event) {
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
                $rawResult = $toolInstance($arguments);
            } catch (\Throwable $e) {
                Log::error('task-assistant.mutating.tool_execution_failed', [
                    'user_id' => $user->id,
                    'thread_id' => $thread->id,
                    'tool' => $toolName,
                    'exception' => $e,
                ]);

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

        try {
            $rawResult = $toolInstance($arguments);
        } catch (\Throwable $e) {
            Log::error('task-assistant.mutating.tool_execution_failed', [
                'user_id' => $user->id,
                'thread_id' => $thread->id,
                'tool' => $toolName,
                'exception' => $e,
            ]);

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
