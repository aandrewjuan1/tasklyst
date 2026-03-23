<?php

namespace App\Services\LLM\TaskAssistant;

use App\Enums\MessageRole;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\Browse\TaskAssistantBrowseListingService;
use App\Services\LLM\Prioritization\TaskAssistantTaskChoiceConstraintsExtractor;
use App\Services\LLM\Prioritization\TaskPrioritizationService;
use App\Services\LLM\Scheduling\TaskAssistantStructuredFlowGenerator;
use App\Support\LLM\TaskAssistantBrowseDefaults;
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

/**
 * Task Assistant orchestration: queued messages are routed once via
 * {@see IntentRoutingPolicy} (LLM + validation), then executed in a flow branch.
 */
final class TaskAssistantService
{
    private const MESSAGE_LIMIT = 50;

    private const STREAM_CHUNK_SIZE = 200;

    public function __construct(
        private readonly TaskAssistantPromptData $promptData,
        private readonly TaskAssistantSnapshotService $snapshotService,
        private readonly TaskAssistantStructuredFlowGenerator $structuredFlowGenerator,
        private readonly TaskAssistantFlowExecutionEngine $flowExecutionEngine,
        private readonly TaskAssistantStreamingBroadcaster $streamingBroadcaster,
        private readonly TaskPrioritizationService $prioritizationService,
        private readonly TaskAssistantTaskChoiceConstraintsExtractor $constraintsExtractor,
        private readonly TaskAssistantConversationStateService $conversationState,
        private readonly IntentRoutingPolicy $routingPolicy,
        private readonly TaskAssistantHybridNarrativeService $hybridNarrative,
        private readonly TaskAssistantBrowseListingService $browseListingService,
        private readonly TaskAssistantToolEventPersister $toolEventPersister,
    ) {}

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
            Log::warning('task-assistant.orchestration', [
                'layer' => 'orchestration',
                'stage' => 'aborted_missing_message',
                'thread_id' => $thread->id,
                'user_message_id' => $userMessageId,
                'assistant_message_id' => $assistantMessageId,
                'has_user_message' => (bool) $userMessage,
                'has_assistant_message' => (bool) $assistantMessage,
            ]);

            return;
        }

        app()->instance('task_assistant.thread_id', $thread->id);
        app()->instance('task_assistant.message_id', $assistantMessageId);

        try {
            Log::info('task-assistant.orchestration', [
                'layer' => 'orchestration',
                'stage' => 'start',
                'thread_id' => $thread->id,
                'user_id' => $thread->user_id,
                'user_message_id' => $userMessageId,
                'assistant_message_id' => $assistantMessageId,
            ]);

            $content = (string) ($userMessage->content ?? '');
            $plan = $this->buildExecutionPlan($thread, $content);
            $this->logRoutingDecision($thread, $assistantMessage, $plan);

            if ($plan->clarificationNeeded) {
                $this->runClarificationFlow($thread, $assistantMessage, $plan);

                return;
            }

            if ($plan->flow === 'prioritize') {
                $this->runPrioritizeFlow($thread, $assistantMessage, $content, $plan);

                return;
            }

            if ($plan->flow === 'schedule') {
                $this->runScheduleFlow($thread, $userMessage, $assistantMessage, $content, $plan);

                return;
            }

            if ($plan->flow === 'browse') {
                $this->runBrowseFlow($thread, $userMessage, $assistantMessage, $content, $plan);

                return;
            }

            $this->runChatFlow($thread, $userMessage, $assistantMessage, $content, $plan);
        } finally {
            app()->forgetInstance('task_assistant.thread_id');
            app()->forgetInstance('task_assistant.message_id');
        }
    }

    private function runPrioritizeFlow(TaskAssistantThread $thread, TaskAssistantMessage $assistantMessage, string $content, ExecutionPlan $plan): void
    {
        Log::info('task-assistant.flow', [
            'layer' => 'flow',
            'flow' => 'prioritize',
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessage->id,
            'count_limit' => $plan->countLimit,
        ]);

        $snapshot = $this->snapshotService->buildForUser($thread->user, 100);
        $context = $this->constraintsExtractor->extract($content);
        $ranked = $this->prioritizationService->prioritizeFocus($snapshot, $context);
        $limit = $plan->countLimit;
        $items = array_slice($ranked, 0, $limit);

        $selected = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $type = (string) ($item['type'] ?? 'task');
            $id = (int) ($item['id'] ?? 0);
            $title = (string) ($item['title'] ?? 'Untitled');
            if ($id <= 0) {
                continue;
            }
            $reason = trim((string) ($item['reasoning'] ?? 'High relevance based on deadline and priority.'));
            $selected[] = [
                'entity_type' => $type,
                'entity_id' => $id,
                'title' => $title,
                'reason' => $reason,
            ];
        }

        $promptData = $this->promptData->forUser($thread->user);
        $promptData['snapshot'] = $snapshot;

        $deterministicSummary = 'Here are your top '.max(1, $limit).' priorities:';

        $narrative = $this->hybridNarrative->refinePrioritize(
            $promptData,
            $content,
            $selected,
            $deterministicSummary,
            $thread->id,
            $thread->user_id,
        );

        $prioritizeData = [
            'summary' => $narrative['summary'],
            'items' => $selected,
            'limit_used' => count($selected),
            'reasoning' => $narrative['reasoning'],
            'assistant_note' => $narrative['assistant_note'],
            'strategy_points' => $narrative['strategy_points'],
            'suggested_next_steps' => $narrative['suggested_next_steps'],
            'assumptions' => $narrative['assumptions'],
        ];
        $generationResult = [
            'valid' => $selected !== [],
            'data' => $prioritizeData,
            'errors' => [],
        ];

        $execution = $this->flowExecutionEngine->executeStructuredFlow(
            flow: 'prioritize',
            metadataKey: 'prioritize',
            thread: $thread,
            assistantMessage: $assistantMessage,
            generationResult: $generationResult,
            assistantFallbackContent: 'I could not prioritize your items yet. Please ask me to list your top tasks again.'
        );

        if ($selected !== []) {
            $selectedForState = array_map(static fn (array $entity): array => [
                'entity_type' => (string) ($entity['entity_type'] ?? ''),
                'entity_id' => (int) ($entity['entity_id'] ?? 0),
                'title' => (string) ($entity['title'] ?? ''),
            ], $selected);
            $this->conversationState->rememberLastListing(
                $thread,
                'prioritize',
                $selectedForState,
                $assistantMessage->id,
                count($selectedForState)
            );
        }

        $this->streamFlowEnvelope(
            thread: $thread,
            assistantMessage: $assistantMessage,
            flow: 'prioritize',
            execution: $execution
        );
    }

    private function runScheduleFlow(
        TaskAssistantThread $thread,
        TaskAssistantMessage $userMessage,
        TaskAssistantMessage $assistantMessage,
        string $content,
        ExecutionPlan $plan,
    ): void {
        Log::info('task-assistant.flow', [
            'layer' => 'flow',
            'flow' => 'schedule',
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessage->id,
            'target_entities_count' => count($plan->targetEntities),
            'time_window_hint' => $plan->timeWindowHint,
        ]);

        $historyMessages = collect($this->mapToPrismMessages($this->loadHistoryMessages($thread, $userMessage->id)));
        $scheduleTargets = $plan->targetEntities;
        $timeWindowHint = $plan->timeWindowHint;

        $result = $this->structuredFlowGenerator->generateDailySchedule(
            thread: $thread,
            userMessageContent: $content,
            historyMessages: $historyMessages,
            options: [
                'target_entities' => $scheduleTargets,
                'time_window_hint' => $timeWindowHint,
            ]
        );

        $execution = $this->flowExecutionEngine->executeStructuredFlow(
            flow: 'daily_schedule',
            metadataKey: 'schedule',
            thread: $thread,
            assistantMessage: $assistantMessage,
            generationResult: $result,
            assistantFallbackContent: 'I had trouble scheduling these items. Please try again with more details.'
        );

        $this->conversationState->rememberScheduleContext($thread, $scheduleTargets, $timeWindowHint);
        $this->streamFlowEnvelope(
            thread: $thread,
            assistantMessage: $assistantMessage,
            flow: 'schedule',
            execution: $execution
        );
    }

    private function runBrowseFlow(
        TaskAssistantThread $thread,
        TaskAssistantMessage $userMessage,
        TaskAssistantMessage $assistantMessage,
        string $content,
        ExecutionPlan $plan,
    ): void {
        Log::info('task-assistant.flow', [
            'layer' => 'flow',
            'flow' => 'browse',
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessage->id,
        ]);

        $taskLimit = max(1, (int) config('task-assistant.browse.snapshot_task_limit', 200));
        $snapshot = $this->snapshotService->buildForUser($thread->user, $taskLimit);
        $selection = $this->browseListingService->build($content, $snapshot);
        $items = $selection['items'];

        $promptData = $this->promptData->forUser($thread->user);
        $promptData['snapshot'] = $snapshot;
        $promptData['route_context'] = (string) config('task-assistant.browse_route_context', '');

        if ($items === []) {
            $fallbacks = TaskAssistantHybridNarrativeService::browseNarrativeFallbacks();
            $emptyReasoning = trim((string) $selection['deterministic_summary']);
            $browseData = [
                'items' => [],
                'limit_used' => 0,
                'reasoning' => TaskAssistantBrowseDefaults::clampBrowseReasoning(
                    $emptyReasoning !== ''
                        ? $emptyReasoning
                        : TaskAssistantBrowseDefaults::reasoningWhenEmpty()
                ),
                'suggested_guidance' => TaskAssistantBrowseDefaults::clampBrowseSuggestedGuidance(
                    $fallbacks['suggested_guidance']
                ),
            ];
        } else {
            $narrative = $this->hybridNarrative->refineBrowseListing(
                $promptData,
                $content,
                $items,
                $selection['deterministic_summary'],
                $selection['filter_context_for_prompt'],
                $selection['ambiguous'],
                $thread->id,
                $thread->user_id,
            );

            $browseData = [
                'items' => $items,
                'limit_used' => count($items),
                'reasoning' => TaskAssistantBrowseDefaults::clampBrowseReasoning((string) $narrative['reasoning']),
                'suggested_guidance' => TaskAssistantBrowseDefaults::clampBrowseSuggestedGuidance((string) $narrative['suggested_guidance']),
            ];
        }

        $generationResult = [
            'valid' => true,
            'data' => $browseData,
            'errors' => [],
        ];

        $execution = $this->flowExecutionEngine->executeStructuredFlow(
            flow: 'browse',
            metadataKey: 'browse',
            thread: $thread,
            assistantMessage: $assistantMessage,
            generationResult: $generationResult,
            assistantFallbackContent: 'I could not build a task list yet. Try again with a bit more detail.',
        );

        $this->streamFlowEnvelope(
            thread: $thread,
            assistantMessage: $assistantMessage,
            flow: 'browse',
            execution: $execution
        );

        if ($items === []) {
            $this->conversationState->clearLastListing($thread);
        } else {
            $this->conversationState->rememberLastListing(
                $thread,
                'browse',
                $items,
                $assistantMessage->id,
            );
        }
    }

    private function runChatFlow(
        TaskAssistantThread $thread,
        TaskAssistantMessage $userMessage,
        TaskAssistantMessage $assistantMessage,
        string $content,
        ExecutionPlan $plan,
    ): void {
        $historyMessages = $this->mapToPrismMessages($this->loadHistoryMessages($thread, $userMessage->id));
        $historyMessages[] = new UserMessage($content);
        $promptData = $this->promptData->forUser($thread->user);
        $promptData['snapshot'] = $this->snapshotService->buildForUser($thread->user);
        $promptData['toolManifest'] = $this->buildToolManifestFromTools($this->resolveToolsForRoute($thread->user, $plan->flow));

        $textResponse = Prism::text()
            ->using($this->resolveProvider(), $this->resolveModel())
            ->withSystemPrompt(view('prompts.task-assistant-system', $promptData))
            ->withMessages($historyMessages)
            ->withTools($this->resolveToolsForRoute($thread->user, $plan->flow))
            ->withMaxSteps(3)
            ->withClientOptions($this->resolveClientOptionsForRoute($plan->generationProfile))
            ->asText();

        Log::info('task-assistant.llm.chat', [
            'layer' => 'llm_chat',
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessage->id,
            'user_message_id' => $userMessage->id,
            'generation_profile' => $plan->generationProfile,
            'provider' => (string) config('task-assistant.provider', 'ollama'),
            'model' => $this->resolveModel(),
            'text_length' => mb_strlen((string) ($textResponse->text ?? '')),
            'tool_calls_count' => count($textResponse->toolCalls ?? []),
            'tool_results_count' => count($textResponse->toolResults ?? []),
            'finish_reason' => isset($textResponse->finishReason)
                ? ($textResponse->finishReason instanceof \UnitEnum
                    ? $textResponse->finishReason->name
                    : (string) $textResponse->finishReason)
                : null,
        ]);

        $this->toolEventPersister->persistToolCallsAndResults(
            $assistantMessage,
            $textResponse->toolCalls,
            $textResponse->toolResults,
        );

        $assistantMessage->update([
            'content' => (string) ($textResponse->text ?? 'How can I help with prioritizing or scheduling your tasks?'),
        ]);

        $this->streamFinalAssistantJson($thread->user_id, $assistantMessage, $this->buildJsonEnvelope(
            flow: 'chat',
            data: ['message' => (string) $assistantMessage->content],
            threadId: $thread->id,
            assistantMessageId: $assistantMessage->id,
            ok: true,
        ));
    }

    private function runClarificationFlow(TaskAssistantThread $thread, TaskAssistantMessage $assistantMessage, ExecutionPlan $plan): void
    {
        Log::info('task-assistant.flow', [
            'layer' => 'flow',
            'flow' => 'clarify',
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessage->id,
            'target_flow' => $plan->flow,
            'reason_codes' => $plan->reasonCodes,
        ]);

        $assistantMessage->update([
            'content' => (string) ($plan->clarificationQuestion ?? 'Could you clarify whether you want prioritization, scheduling, or general assistance?'),
            'metadata' => array_merge($assistantMessage->metadata ?? [], [
                'clarification' => [
                    'needed' => true,
                    'reason_codes' => $plan->reasonCodes,
                    'confidence' => $plan->confidence,
                    'target_flow' => $plan->flow,
                ],
            ]),
        ]);

        $state = $this->conversationState->get($thread);
        $state['pending_clarification'] = [
            'target_flow' => $plan->flow,
            'reason_codes' => $plan->reasonCodes,
        ];
        $this->conversationState->put($thread, $state);

        $this->streamFinalAssistantJson($thread->user_id, $assistantMessage, $this->buildJsonEnvelope(
            flow: 'clarify',
            data: [
                'message' => (string) $assistantMessage->content,
                'reason_codes' => $plan->reasonCodes,
            ],
            threadId: $thread->id,
            assistantMessageId: $assistantMessage->id,
            ok: false,
        ));
    }

    /**
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
     * @param  Collection<int, TaskAssistantMessage>  $messages
     * @return array<int, UserMessage|AssistantMessage|ToolResultMessage>
     */
    private function mapToPrismMessages(Collection $messages): array
    {
        $out = [];
        foreach ($messages as $msg) {
            if ($msg->role === MessageRole::User) {
                $out[] = new UserMessage($msg->content ?? '');

                continue;
            }
            if ($msg->role === MessageRole::Assistant) {
                $prismToolCalls = [];
                foreach ($msg->tool_calls ?? [] as $tc) {
                    if (! is_array($tc)) {
                        continue;
                    }
                    $id = (string) ($tc['id'] ?? '');
                    if ($id === '') {
                        continue;
                    }
                    $prismToolCalls[] = new ToolCall(
                        id: $id,
                        name: (string) ($tc['name'] ?? ''),
                        arguments: $tc['arguments'] ?? [],
                    );
                }
                $out[] = new AssistantMessage($msg->content ?? '', $prismToolCalls);

                continue;
            }
            if ($msg->role === MessageRole::Tool) {
                $meta = $msg->metadata ?? [];
                $toolCallId = (string) ($meta['tool_call_id'] ?? '');
                if ($toolCallId === '') {
                    continue;
                }
                $toolName = (string) ($meta['tool_name'] ?? '');
                $args = is_array($meta['args'] ?? null) ? $meta['args'] : [];
                $result = $this->decodeToolMessageResult((string) ($msg->content ?? ''));
                $out[] = new ToolResultMessage([
                    new ToolResult($toolCallId, $toolName, $args, $result),
                ]);
            }
        }

        return $out;
    }

    private function decodeToolMessageResult(string $raw): array|float|int|string|null
    {
        $trim = trim($raw);
        if ($trim === '') {
            return '';
        }
        if (str_starts_with($trim, '{') || str_starts_with($trim, '[')) {
            $decoded = json_decode($trim, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (is_bool($decoded)) {
                    return $decoded ? 'true' : 'false';
                }

                return $decoded;
            }
        }

        return $raw;
    }

    /**
     * @return Tool[]
     */
    private function resolveToolsForRoute(User $user, string $route): array
    {
        $tools = [];
        $config = config('prism-tools', []);
        $routeTools = config('task-assistant.tools.routes.'.$route, []);
        if (! is_array($routeTools)) {
            $routeTools = [];
        }

        foreach ($routeTools as $key) {
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

    private function resolveProvider(): Provider
    {
        $provider = strtolower((string) config('task-assistant.provider', 'ollama'));

        return match ($provider) {
            'ollama' => Provider::Ollama,
            default => $this->fallbackProvider($provider),
        };
    }

    private function fallbackProvider(string $provider): Provider
    {
        Log::warning('task-assistant.provider.fallback', [
            'layer' => 'llm_chat',
            'requested_provider' => $provider,
            'fallback_provider' => 'ollama',
        ]);

        return Provider::Ollama;
    }

    private function resolveModel(): string
    {
        return (string) config('task-assistant.model', 'hermes3:3b');
    }

    /**
     * @return array<string, int|float|null>
     */
    private function resolveClientOptionsForRoute(string $route): array
    {
        $temperature = config('task-assistant.generation.'.$route.'.temperature');
        $maxTokens = config('task-assistant.generation.'.$route.'.max_tokens');
        $topP = config('task-assistant.generation.'.$route.'.top_p');

        return [
            'timeout' => (int) config('prism.request_timeout', 120),
            'temperature' => is_numeric($temperature) ? (float) $temperature : (float) config('task-assistant.generation.temperature', 0.3),
            'max_tokens' => is_numeric($maxTokens) ? (int) $maxTokens : (int) config('task-assistant.generation.max_tokens', 1200),
            'top_p' => is_numeric($topP) ? (float) $topP : (float) config('task-assistant.generation.top_p', 0.9),
        ];
    }

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
     * @param  array{structured_data?: array<string, mixed>, final_valid?: bool}  $execution
     */
    private function streamFlowEnvelope(
        TaskAssistantThread $thread,
        TaskAssistantMessage $assistantMessage,
        string $flow,
        array $execution
    ): void {
        $this->streamFinalAssistantJson(
            $thread->user_id,
            $assistantMessage,
            $this->buildJsonEnvelope(
                flow: $flow,
                data: is_array($execution['structured_data'] ?? null) ? $execution['structured_data'] : [],
                threadId: $thread->id,
                assistantMessageId: $assistantMessage->id,
                ok: (bool) ($execution['final_valid'] ?? false),
            )
        );
    }

    /**
     * Builds the execution plan using the application intent pipeline (no alternate router).
     */
    private function buildExecutionPlan(TaskAssistantThread $thread, string $content): ExecutionPlan
    {
        $decision = $this->routingPolicy->decide($thread, $content);
        $constraints = $decision->constraints;
        $flow = in_array($decision->flow, ['chat', 'browse', 'prioritize', 'schedule'], true) ? $decision->flow : 'chat';
        $countLimit = max(1, min((int) ($constraints['count_limit'] ?? 3), 10));
        $timeWindowHint = is_string($constraints['time_window_hint'] ?? null) ? $constraints['time_window_hint'] : null;
        $targetEntities = is_array($constraints['target_entities'] ?? null) ? $constraints['target_entities'] : [];

        $generationProfile = match ($flow) {
            'schedule' => 'schedule',
            'prioritize' => 'prioritize',
            'browse' => 'browse',
            default => 'chat',
        };

        return new ExecutionPlan(
            flow: $flow,
            confidence: $decision->confidence,
            clarificationNeeded: $decision->clarificationNeeded,
            clarificationQuestion: $decision->clarificationQuestion,
            reasonCodes: $decision->reasonCodes,
            constraints: $constraints,
            targetEntities: $targetEntities,
            timeWindowHint: $timeWindowHint,
            countLimit: $countLimit,
            generationProfile: $generationProfile,
        );
    }

    private function logRoutingDecision(TaskAssistantThread $thread, TaskAssistantMessage $assistantMessage, ExecutionPlan $plan): void
    {
        Log::info('task-assistant.routing_decision', [
            'layer' => 'routing',
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessage->id,
            'flow' => $plan->flow,
            'confidence' => $plan->confidence,
            'clarification_needed' => $plan->clarificationNeeded,
            'reason_codes' => $plan->reasonCodes,
            'target_entities_count' => count($plan->targetEntities),
            'time_window_hint' => $plan->timeWindowHint,
            'count_limit' => $plan->countLimit,
            'generation_profile' => $plan->generationProfile,
            'intent_use_llm' => (bool) config('task-assistant.intent.use_llm', true),
        ]);
    }
}
