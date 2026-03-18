<?php

namespace App\Services;

use App\Enums\MessageRole;
use App\Enums\TaskAssistantIntent;
use App\Events\TaskAssistantJsonDelta;
use App\Events\TaskAssistantStreamEnd;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    private const STREAM_CHUNK_SIZE = 40;

    public function __construct(
        private readonly TaskAssistantPromptData $promptData,
        private readonly TaskAssistantSnapshotService $snapshotService,
        private readonly TaskAssistantToolInterpreter $toolInterpreter,
    ) {}

    /**
     * Run a single request with tools and streaming; persist user and assistant messages.
     */
    public function streamResponse(TaskAssistantThread $thread, string $userMessageContent, TaskAssistantIntent $intent = TaskAssistantIntent::GeneralAdvice): StreamedResponse
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
        $snapshot = $this->snapshotService->buildForUser($user);
        Log::info('task-assistant.snapshot', [
            'user_id' => $user->id,
            'thread_id' => $thread->id,
            'snapshot' => $snapshot,
        ]);
        $promptData['snapshot'] = $snapshot;
        $timeout = (int) config('prism.request_timeout', 60);

        $this->bindTaskAssistantContext($thread->id, $assistantMessage->id);

        $schema = \App\Support\TaskAssistantSchemas::advisorySchema();

        return response()->stream(function () use ($assistantMessage, $prismMessages, $tools, $promptData, $timeout, $thread): void {
            try {
                $structuredResponse = Prism::structured()
                    ->using(Provider::Ollama, 'hermes3:3b')
                    ->withSystemPrompt(view('prompts.task-assistant-system', $promptData))
                    ->withMessages($prismMessages)
                    ->withTools($tools)
                    ->withSchema(\App\Support\TaskAssistantSchemas::advisorySchema())
                    ->withMaxSteps(3)
                    ->withClientOptions(['timeout' => $timeout])
                    ->asStructured();

                $payload = $structuredResponse->structured ?? [];
                if (! is_array($payload)) {
                    $payload = [];
                }

                $envelope = $this->buildJsonEnvelope(flow: 'advisory', data: $payload, threadId: $thread->id, assistantMessageId: $assistantMessage->id);
                $json = $this->encodeJson($envelope);

                $assistantMessage->update([
                    'content' => $json,
                    'metadata' => array_merge($assistantMessage->metadata ?? [], [
                        'structured' => $envelope,
                    ]),
                ]);

                foreach (mb_str_split($json, self::STREAM_CHUNK_SIZE) as $chunk) {
                    if ($chunk === '') {
                        continue;
                    }

                    echo $chunk;
                    @ob_flush();
                    flush();
                }
            } finally {
                $this->clearTaskAssistantContext();
            }
        }, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /**
     * Run the \"choose next task and break into steps\" flow using structured JSON output.
     *
     * @return array{valid: bool, data: array<string, mixed>, errors: array<int, string>, user_message: string}
     */
    public function runTaskChoice(TaskAssistantThread $thread, string $userMessageContent, TaskAssistantTaskChoiceRunner $runner, TaskAssistantIntent $intent = TaskAssistantIntent::PlanNextTask): array
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

            $assistantContent = $result['valid']
                ? $this->renderTaskChoiceMessage($result['data'])
                : 'I had trouble understanding that suggestion. You can try asking again or pick a task directly from your list.';

            $assistantMessage->update([
                'content' => $assistantContent,
                'metadata' => $result['valid']
                    ? array_merge($assistantMessage->metadata ?? [], [
                        'task_choice' => $result['data'],
                    ])
                    : ($assistantMessage->metadata ?? []),
            ]);

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
    public function runDailySchedule(TaskAssistantThread $thread, string $userMessageContent, TaskAssistantDailyScheduleRunner $runner, TaskAssistantIntent $intent = TaskAssistantIntent::PlanNextTask): array
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

            $assistantContent = $result['valid']
                ? $this->renderDailyScheduleMessage($result['data'])
                : 'I had trouble generating a schedule. You can try asking again or sketch one directly on your calendar.';

            $assistantMessage->update([
                'content' => $assistantContent,
                'metadata' => $result['valid']
                    ? array_merge($assistantMessage->metadata ?? [], [
                        'daily_schedule' => $result['data'],
                    ])
                    : ($assistantMessage->metadata ?? []),
            ]);

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
    public function runStudyPlan(TaskAssistantThread $thread, string $userMessageContent, TaskAssistantStudyPlanRunner $runner, TaskAssistantIntent $intent = TaskAssistantIntent::PlanNextTask): array
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

            $assistantContent = $result['valid']
                ? $this->renderStudyPlanMessage($result['data'])
                : 'I had trouble generating a study plan. You can try asking again or sketch a short list directly.';

            $assistantMessage->update([
                'content' => $assistantContent,
                'metadata' => $result['valid']
                    ? array_merge($assistantMessage->metadata ?? [], [
                        'study_plan' => $result['data'],
                    ])
                    : ($assistantMessage->metadata ?? []),
            ]);

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
    public function runReviewSummary(TaskAssistantThread $thread, string $userMessageContent, TaskAssistantReviewSummaryRunner $runner, TaskAssistantIntent $intent = TaskAssistantIntent::PlanNextTask): array
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

            $assistantContent = $result['valid']
                ? $this->renderReviewSummaryMessage($result['data'])
                : 'I had trouble summarizing your work. You can try asking again or review your task list directly.';

            $assistantMessage->update([
                'content' => $assistantContent,
                'metadata' => $result['valid']
                    ? array_merge($assistantMessage->metadata ?? [], [
                        'review_summary' => $result['data'],
                    ])
                    : ($assistantMessage->metadata ?? []),
            ]);

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
            $snapshot = $this->snapshotService->buildForUser($user);
            Log::info('task-assistant.snapshot', [
                'user_id' => $user->id,
                'thread_id' => $thread->id,
                'snapshot' => $snapshot,
            ]);
            $promptData['snapshot'] = $snapshot;

            $timeout = (int) config('prism.request_timeout', 60);

            $schema = \App\Support\TaskAssistantSchemas::mutatingSuggestionSchema();

            $structuredResponse = Prism::structured()
                ->using(Provider::Ollama, 'hermes3:3b')
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
    public function broadcastStream(TaskAssistantThread $thread, int $userMessageId, int $assistantMessageId, TaskAssistantIntent $intent = TaskAssistantIntent::GeneralAdvice): void
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
        $snapshot = $this->snapshotService->buildForUser($user);
        Log::info('task-assistant.snapshot', [
            'user_id' => $user->id,
            'thread_id' => $thread->id,
            'snapshot' => $snapshot,
        ]);
        $promptData['snapshot'] = $snapshot;
        $timeout = (int) config('prism.request_timeout', 60);
        $channel = new Channel('task-assistant.user.'.$user->id);

        $this->bindTaskAssistantContext($thread->id, $assistantMessage->id);

        try {
            Log::info('task-assistant.broadcastStream.prism_start', [
                'thread_id' => $thread->id,
                'assistant_message_id' => $assistantMessageId,
                'channel' => $channel->name,
            ]);

            $schema = \App\Support\TaskAssistantSchemas::advisorySchema();

            $structuredResponse = Prism::structured()
                ->using(Provider::Ollama, 'hermes3:3b')
                ->withSystemPrompt(view('prompts.task-assistant-system', $promptData))
                ->withMessages($prismMessages)
                ->withTools($tools)
                ->withSchema($schema)
                ->withMaxSteps(3)
                ->withClientOptions(['timeout' => $timeout])
                ->asStructured();

            $payload = $structuredResponse->structured ?? [];
            if (! is_array($payload)) {
                $payload = [];
            }

            $envelope = $this->buildJsonEnvelope(flow: 'advisory', data: $payload, threadId: $thread->id, assistantMessageId: $assistantMessage->id);
            $this->streamFinalAssistantJson($user->id, $assistantMessage, $envelope);
        } finally {
            Log::info('task-assistant.broadcastStream.end', [
                'thread_id' => $thread->id,
                'assistant_message_id' => $assistantMessageId,
            ]);
            $this->clearTaskAssistantContext();
        }
    }

    /**
     * Unified async entrypoint: decide flow, run it, and stream output over Reverb.
     *
     * All flows update the existing assistant message and broadcast `.json_delta` + `.stream_end`.
     */
    public function processQueuedMessage(TaskAssistantThread $thread, int $userMessageId, int $assistantMessageId): void
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

        $content = trim((string) ($userMessage->content ?? ''));
        $flow = $this->detectFlow($content);

        Log::info('task-assistant.processQueuedMessage.start', [
            'thread_id' => $thread->id,
            'user_message_id' => $userMessageId,
            'assistant_message_id' => $assistantMessageId,
            'flow' => $flow,
        ]);

        if ($flow === 'advisory') {
            $this->broadcastStream($thread, $userMessageId, $assistantMessageId, TaskAssistantIntent::GeneralAdvice);

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
                /** @var TaskAssistantDailyScheduleRunner $runner */
                $runner = app(TaskAssistantDailyScheduleRunner::class);
                $result = $this->runDailyScheduleOnExistingMessages($thread, $userMessage, $assistantMessage, $runner);
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
                /** @var TaskAssistantStudyPlanRunner $runner */
                $runner = app(TaskAssistantStudyPlanRunner::class);
                $result = $this->runStudyPlanOnExistingMessages($thread, $userMessage, $assistantMessage, $runner);
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
                /** @var TaskAssistantReviewSummaryRunner $runner */
                $runner = app(TaskAssistantReviewSummaryRunner::class);
                $result = $this->runReviewSummaryOnExistingMessages($thread, $userMessage, $assistantMessage, $runner);
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
            $this->broadcastStream($thread, $userMessageId, $assistantMessageId, TaskAssistantIntent::GeneralAdvice);
        } finally {
            $this->clearTaskAssistantContext();
        }
    }

    /**
     * Mirror of the Livewire classifier so the backend decides in the job.
     */
    private function detectFlow(string $content): string
    {
        $q = mb_strtolower($content);

        if (preg_match('/\\b(create|update|delete|restore|complete|mark|archive|list)\\b/', $q) === 1) {
            return 'mutating';
        }

        if (preg_match('/(what should i work on|help me choose|choose.*next task|pick.*next task)/', $q) === 1) {
            return 'task_choice';
        }

        if (preg_match('/(schedule|propose.*schedule|plan my day|today.*schedule)/', $q) === 1) {
            return 'daily_schedule';
        }

        if (preg_match('/(study plan|revision plan|study schedule|revise)/', $q) === 1) {
            return 'study_plan';
        }

        if (preg_match('/(review.*done|what have i done|summary of work|progress summary)/', $q) === 1) {
            return 'review_summary';
        }

        return 'advisory';
    }

    /**
     * Broadcast a final assistant message as `.json_delta` chunks, then `.stream_end`.
     */
    private function streamFinalAssistantJson(int $userId, TaskAssistantMessage $assistantMessage, array $envelope): void
    {
        $json = $this->encodeJson($envelope);

        $assistantMessage->update([
            'content' => $json,
            'metadata' => array_merge($assistantMessage->metadata ?? [], [
                'structured' => $envelope,
            ]),
        ]);

        foreach (mb_str_split($json, self::STREAM_CHUNK_SIZE) as $chunk) {
            if ($chunk === '') {
                continue;
            }

            broadcast(new TaskAssistantJsonDelta($userId, $chunk));
        }

        broadcast(new TaskAssistantStreamEnd($userId));
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
        $tools = $this->resolveToolsForIntent($user, TaskAssistantIntent::PlanNextTask);
        $result = $runner->run($thread, (string) $userMessage->content, $prismMessages, $tools);

        $assistantContent = $result['valid']
            ? $this->renderTaskChoiceMessage($result['data'])
            : 'I had trouble understanding that suggestion. You can try asking again or pick a task directly from your list.';

        $assistantMessage->update([
            'content' => $assistantContent,
            'metadata' => $result['valid']
                ? array_merge($assistantMessage->metadata ?? [], ['task_choice' => $result['data']])
                : ($assistantMessage->metadata ?? []),
        ]);

        return [
            'valid' => $result['valid'],
            'data' => $result['data'],
            'errors' => $result['errors'],
            'user_message' => $assistantContent,
        ];
    }

    private function runDailyScheduleOnExistingMessages(
        TaskAssistantThread $thread,
        TaskAssistantMessage $userMessage,
        TaskAssistantMessage $assistantMessage,
        TaskAssistantDailyScheduleRunner $runner
    ): array {
        $user = $thread->user;
        $historyMessages = $this->loadHistoryMessages($thread, $userMessage->id);
        $prismMessages = collect($this->mapToPrismMessages($historyMessages));
        $tools = $this->resolveToolsForIntent($user, TaskAssistantIntent::PlanNextTask);
        $result = $runner->run($thread, (string) $userMessage->content, $prismMessages, $tools);

        $assistantContent = $result['valid']
            ? $this->renderDailyScheduleMessage($result['data'])
            : 'I had trouble generating a schedule. You can try asking again or sketch one directly on your calendar.';

        $assistantMessage->update([
            'content' => $assistantContent,
            'metadata' => $result['valid']
                ? array_merge($assistantMessage->metadata ?? [], ['daily_schedule' => $result['data']])
                : ($assistantMessage->metadata ?? []),
        ]);

        return [
            'valid' => $result['valid'],
            'data' => $result['data'],
            'errors' => $result['errors'],
            'user_message' => $assistantContent,
        ];
    }

    private function runStudyPlanOnExistingMessages(
        TaskAssistantThread $thread,
        TaskAssistantMessage $userMessage,
        TaskAssistantMessage $assistantMessage,
        TaskAssistantStudyPlanRunner $runner
    ): array {
        $user = $thread->user;
        $historyMessages = $this->loadHistoryMessages($thread, $userMessage->id);
        $prismMessages = collect($this->mapToPrismMessages($historyMessages));
        $tools = $this->resolveToolsForIntent($user, TaskAssistantIntent::PlanNextTask);
        $result = $runner->run($thread, (string) $userMessage->content, $prismMessages, $tools);

        $assistantContent = $result['valid']
            ? $this->renderStudyPlanMessage($result['data'])
            : 'I had trouble generating a study plan. You can try asking again or sketch a short list directly.';

        $assistantMessage->update([
            'content' => $assistantContent,
            'metadata' => $result['valid']
                ? array_merge($assistantMessage->metadata ?? [], ['study_plan' => $result['data']])
                : ($assistantMessage->metadata ?? []),
        ]);

        return [
            'valid' => $result['valid'],
            'data' => $result['data'],
            'errors' => $result['errors'],
            'user_message' => $assistantContent,
        ];
    }

    private function runReviewSummaryOnExistingMessages(
        TaskAssistantThread $thread,
        TaskAssistantMessage $userMessage,
        TaskAssistantMessage $assistantMessage,
        TaskAssistantReviewSummaryRunner $runner
    ): array {
        $user = $thread->user;
        $historyMessages = $this->loadHistoryMessages($thread, $userMessage->id);
        $prismMessages = collect($this->mapToPrismMessages($historyMessages));
        $tools = $this->resolveToolsForIntent($user, TaskAssistantIntent::PlanNextTask);
        $result = $runner->run($thread, (string) $userMessage->content, $prismMessages, $tools);

        $assistantContent = $result['valid']
            ? $this->renderReviewSummaryMessage($result['data'])
            : 'I had trouble summarizing your work. You can try asking again or review your task list directly.';

        $assistantMessage->update([
            'content' => $assistantContent,
            'metadata' => $result['valid']
                ? array_merge($assistantMessage->metadata ?? [], ['review_summary' => $result['data']])
                : ($assistantMessage->metadata ?? []),
        ]);

        return [
            'valid' => $result['valid'],
            'data' => $result['data'],
            'errors' => $result['errors'],
            'user_message' => $assistantContent,
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
        $snapshot = $this->snapshotService->buildForUser($user);
        $promptData['snapshot'] = $snapshot;
        $timeout = (int) config('prism.request_timeout', 60);
        $schema = \App\Support\TaskAssistantSchemas::mutatingSuggestionSchema();

        $structuredResponse = Prism::structured()
            ->using(Provider::Ollama, 'hermes3:3b')
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
        if ($intent === TaskAssistantIntent::PlanNextTask || $intent === TaskAssistantIntent::GeneralAdvice) {
            return [];
        }

        return $this->resolveTools($user);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderTaskChoiceMessage(array $data): string
    {
        $lines = [];

        $taskId = $data['chosen_task_id'] ?? null;
        $taskTitle = $data['chosen_task_title'] ?? null;
        if ($taskId !== null && $taskTitle !== null && $taskTitle !== '') {
            $lines[] = 'Next task: ['.$taskId.'] '.$taskTitle;
        }

        $summary = (string) ($data['summary'] ?? '');
        if ($summary !== '') {
            $lines[] = $summary;
        }

        $steps = $data['suggested_next_steps'] ?? [];
        if (is_array($steps) && $steps !== []) {
            $lines[] = 'Next steps:';
            foreach (array_slice($steps, 0, 5) as $i => $step) {
                $step = trim((string) $step);
                if ($step === '') {
                    continue;
                }
                $lines[] = ($i + 1).'. '.Str::limit($step, 160);
            }
        }

        return trim(implode("\n", $lines)) ?: 'Here is a structured plan for your next task.';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderDailyScheduleMessage(array $data): string
    {
        $lines = [];
        $summary = (string) ($data['summary'] ?? '');
        if ($summary !== '') {
            $lines[] = $summary;
        }

        $blocks = $data['blocks'] ?? [];
        if (is_array($blocks) && $blocks !== []) {
            $lines[] = 'Blocks:';
            foreach (array_slice($blocks, 0, 6) as $block) {
                if (! is_array($block)) {
                    continue;
                }
                $start = (string) ($block['start_time'] ?? '');
                $end = (string) ($block['end_time'] ?? '');
                $label = (string) ($block['label'] ?? '');
                $taskId = $block['task_id'] ?? null;
                $eventId = $block['event_id'] ?? null;

                $ref = '';
                if ($taskId !== null) {
                    $ref = '[task '.$taskId.']';
                } elseif ($eventId !== null) {
                    $ref = '[event '.$eventId.']';
                } elseif ($label !== '') {
                    $ref = $label;
                } else {
                    $ref = 'Focus block';
                }

                $time = trim($start.'–'.$end, '–');
                $lines[] = trim($time.' — '.$ref);
            }
        }

        return trim(implode("\n", $lines)) ?: 'Here is a proposed schedule for your day.';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderStudyPlanMessage(array $data): string
    {
        $lines = [];
        $summary = (string) ($data['summary'] ?? '');
        if ($summary !== '') {
            $lines[] = $summary;
        }

        $items = $data['items'] ?? [];
        if (is_array($items) && $items !== []) {
            $lines[] = 'Plan:';
            foreach (array_slice($items, 0, 6) as $i => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $label = trim((string) ($item['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $minutes = $item['estimated_minutes'] ?? null;
                $suffix = is_numeric($minutes) ? ' ('.(int) $minutes.' min)' : '';
                $lines[] = ($i + 1).'. '.Str::limit($label, 160).$suffix;
            }
        }

        return trim(implode("\n", $lines)) ?: 'Here is a structured study or revision plan.';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderReviewSummaryMessage(array $data): string
    {
        $summary = trim((string) ($data['summary'] ?? ''));
        $nextSteps = $data['next_steps'] ?? [];

        $lines = [];
        if ($summary !== '') {
            $lines[] = $summary;
        }

        if (is_array($nextSteps) && $nextSteps !== []) {
            $lines[] = 'Next steps:';
            foreach (array_slice($nextSteps, 0, 5) as $i => $step) {
                $step = trim((string) $step);
                if ($step === '') {
                    continue;
                }
                $lines[] = ($i + 1).'. '.Str::limit($step, 160);
            }
        }

        return trim(implode("\n", $lines)) ?: 'Here is a short review summary of your tasks.';
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodeJson(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '{}';
    }
}
