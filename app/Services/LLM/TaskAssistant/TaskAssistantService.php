<?php

namespace App\Services\LLM\TaskAssistant;

use App\Enums\MessageRole;
use App\Enums\TaskAssistantPrioritizeVariant;
use App\Enums\TaskComplexity;
use App\Enums\TaskStatus;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use App\Services\LLM\Browse\TaskAssistantListingSelectionService;
use App\Services\LLM\Prioritization\AssistantCandidateProvider;
use App\Services\LLM\Prioritization\TaskAssistantTaskChoiceConstraintsExtractor;
use App\Services\LLM\Prioritization\TaskPrioritizationService;
use App\Services\LLM\Scheduling\TaskAssistantStructuredFlowGenerator;
use App\Support\LLM\TaskAssistantListingDefaults;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    public function __construct(
        private readonly TaskAssistantPromptData $promptData,
        private readonly TaskAssistantSnapshotService $snapshotService,
        private readonly TaskAssistantStructuredFlowGenerator $structuredFlowGenerator,
        private readonly TaskAssistantFlowExecutionEngine $flowExecutionEngine,
        private readonly TaskAssistantStreamingBroadcaster $streamingBroadcaster,
        private readonly TaskPrioritizationService $prioritizationService,
        private readonly TaskAssistantTaskChoiceConstraintsExtractor $constraintsExtractor,
        private readonly AssistantCandidateProvider $candidateProvider,
        private readonly TaskAssistantConversationStateService $conversationState,
        private readonly TaskAssistantGeneralGuidanceService $generalGuidanceService,
        private readonly IntentRoutingPolicy $routingPolicy,
        private readonly TaskAssistantHybridNarrativeService $hybridNarrative,
        private readonly TaskAssistantListingSelectionService $listingSelectionService,
        private readonly PrioritizeVariantResolver $prioritizeVariantResolver,
    ) {}

    public function processQueuedMessage(TaskAssistantThread $thread, int $userMessageId, int $assistantMessageId): void
    {
        // Ensure we have the latest persisted thread metadata/state. In production the
        // queued job loads fresh models; in-process calls (tests) can reuse instances.
        $thread->refresh();

        $runId = (string) Str::uuid();
        app()->instance('task_assistant.run_id', $runId);

        if ($this->isCancellationRequested($thread, $assistantMessageId)) {
            Log::info('task-assistant.orchestration', [
                'layer' => 'orchestration',
                'stage' => 'cancelled_before_processing',
                'run_id' => $runId,
                'thread_id' => $thread->id,
                'assistant_message_id' => $assistantMessageId,
            ]);

            $this->markCancelled($thread, $assistantMessageId);

            return;
        }

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
                'run_id' => $runId,
                'thread_id' => $thread->id,
                'user_message_id' => $userMessageId,
                'assistant_message_id' => $assistantMessageId,
                'has_user_message' => (bool) $userMessage,
                'has_assistant_message' => (bool) $assistantMessage,
            ]);

            return;
        }

        if ($this->isCancellationRequested($thread, $assistantMessageId)) {
            Log::info('task-assistant.orchestration', [
                'layer' => 'orchestration',
                'stage' => 'cancelled_after_message_load',
                'run_id' => $runId,
                'thread_id' => $thread->id,
                'assistant_message_id' => $assistantMessageId,
            ]);

            $this->markCancelled($thread, $assistantMessageId);

            return;
        }

        app()->instance('task_assistant.thread_id', $thread->id);
        app()->instance('task_assistant.message_id', $assistantMessageId);

        try {
            Log::info('task-assistant.orchestration', [
                'layer' => 'orchestration',
                'stage' => 'start',
                'run_id' => $runId,
                'thread_id' => $thread->id,
                'user_id' => $thread->user_id,
                'user_message_id' => $userMessageId,
                'assistant_message_id' => $assistantMessageId,
            ]);

            $content = (string) ($userMessage->content ?? '');
            Log::debug('task-assistant.user_message', [
                'layer' => 'orchestration',
                'stage' => 'user_message_loaded',
                'run_id' => $runId,
                'thread_id' => $thread->id,
                'assistant_message_id' => $assistantMessageId,
                'content_length' => mb_strlen($content),
                'content_preview' => $this->previewForLogs($content),
            ]);

            $pending = $this->conversationState->pendingGeneralGuidance($thread);
            if ($pending !== null && ! $this->looksLikeStandaloneGeneralGuidancePrompt($content)) {
                $targetDecision = $this->generalGuidanceService->resolveTargetFromAnswer(
                    $thread->user,
                    (string) $pending['clarifying_question'],
                    $content
                );
                $target = (string) ($targetDecision['target'] ?? 'either');
                $confidence = (float) ($targetDecision['confidence'] ?? 0.0);

                if (in_array($target, ['prioritize', 'schedule'], true) && $confidence >= 0.6) {
                    $forcedFlow = $target;
                    $forcedConstraints = $this->routingPolicy->extractConstraintsForFlow($thread, $content, $forcedFlow);
                    $forcedCountLimit = max(1, min((int) ($forcedConstraints['count_limit'] ?? 3), 10));
                    $forcedTimeWindowHint = is_string($forcedConstraints['time_window_hint'] ?? null)
                        ? $forcedConstraints['time_window_hint']
                        : null;
                    $forcedTargetEntities = is_array($forcedConstraints['target_entities'] ?? null)
                        ? $forcedConstraints['target_entities']
                        : [];
                    $forcedReasonCodes = array_merge(
                        is_array($pending['reason_codes'] ?? null) ? $pending['reason_codes'] : [],
                        ['pending_general_guidance_forced_'.$forcedFlow]
                    );

                    $this->conversationState->clearPendingGeneralGuidance($thread);

                    $forcedPrioritizeVariant = $forcedFlow === 'prioritize'
                        ? $this->prioritizeVariantResolver->resolve(
                            $thread,
                            $content,
                            $forcedConstraints,
                            $forcedReasonCodes,
                        )->variant
                        : null;

                    $forcedPlan = new ExecutionPlan(
                        flow: $forcedFlow,
                        confidence: 1.0,
                        clarificationNeeded: false,
                        clarificationQuestion: null,
                        reasonCodes: $forcedReasonCodes,
                        constraints: $forcedConstraints,
                        targetEntities: $forcedTargetEntities,
                        timeWindowHint: $forcedTimeWindowHint,
                        countLimit: $forcedCountLimit,
                        generationProfile: $forcedFlow,
                        prioritizeVariant: $forcedPrioritizeVariant,
                    );

                    $this->logRoutingDecision($thread, $assistantMessage, $forcedPlan);

                    if ($forcedFlow === 'prioritize') {
                        $this->runPrioritizeFlow($thread, $assistantMessage, $content, $forcedPlan);

                        return;
                    }

                    $this->runScheduleFlow($thread, $userMessage, $assistantMessage, $content, $forcedPlan);

                    return;
                }
            }

            $plan = $this->buildExecutionPlan($thread, $content);
            $this->logRoutingDecision($thread, $assistantMessage, $plan);

            if ($plan->flow === 'general_guidance') {
                $this->runGeneralGuidanceFlow($thread, $assistantMessage, $content, $plan);

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

            throw new \UnexpectedValueException('Unsupported task assistant flow: '.$plan->flow);
        } catch (\Throwable $e) {
            Log::error('task-assistant.orchestration', [
                'layer' => 'orchestration',
                'stage' => 'unhandled_exception',
                'run_id' => $runId,
                'thread_id' => $thread->id,
                'assistant_message_id' => $assistantMessageId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            throw $e;
        } finally {
            app()->forgetInstance('task_assistant.thread_id');
            app()->forgetInstance('task_assistant.message_id');
            app()->forgetInstance('task_assistant.run_id');
        }
    }

    private function runPrioritizeFlow(TaskAssistantThread $thread, TaskAssistantMessage $assistantMessage, string $content, ExecutionPlan $plan): void
    {
        $thread->refresh();

        $variant = $plan->prioritizeVariant ?? TaskAssistantPrioritizeVariant::Rank;

        Log::info('task-assistant.flow', [
            'layer' => 'flow',
            'flow' => 'prioritize',
            'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessage->id,
            'count_limit' => $plan->countLimit,
            'prioritize_variant' => $variant->value,
        ]);

        $context = $this->constraintsExtractor->extract($content);
        $isNextSliceFollowup = $variant === TaskAssistantPrioritizeVariant::FollowupSlice;

        $useBrowseEngine = match ($variant) {
            TaskAssistantPrioritizeVariant::Browse => true,
            TaskAssistantPrioritizeVariant::Rank => false,
            TaskAssistantPrioritizeVariant::FollowupSlice => $this->resolvePrioritizeFollowupListingEngine($thread) === 'browse',
        };

        if ($isNextSliceFollowup) {
            $context = $this->inheritEntityTypePreferenceForPrioritizeFollowup($thread, $context);
        }
        $seenEntityKeys = $isNextSliceFollowup
            ? $this->conversationState->prioritizeShownEntityKeys($thread)
            : [];

        if (! $isNextSliceFollowup) {
            $this->conversationState->clearPrioritizePagination($thread);
        }

        $items = [];
        $prioritizeData = [];

        if ($useBrowseEngine) {
            $taskLimit = max(1, (int) config('task-assistant.listing.snapshot_task_limit', 200));
            $snapshot = $this->candidateProvider->candidatesForUser(
                $thread->user,
                taskLimit: $taskLimit,
            );
            $selectionLimit = $isNextSliceFollowup
                ? max($plan->countLimit + count($seenEntityKeys), 50)
                : $plan->countLimit;
            $selection = $this->listingSelectionService->build($content, $snapshot, $selectionLimit);
            $allItems = is_array($selection['items'] ?? null) ? $selection['items'] : [];
            [$items, $hasMoreUnseen, $isExhaustedNextSlice] = $this->applyPrioritizeUnseenSlice(
                $allItems,
                $plan->countLimit,
                $seenEntityKeys,
                $isNextSliceFollowup
            );

            $promptData = $this->promptData->forUser($thread->user);
            $promptData['snapshot'] = $snapshot;
            $promptData['route_context'] = (string) config('task-assistant.listing_route_context', '');

            if ($isExhaustedNextSlice) {
                $prioritizeData = $this->buildPrioritizeExhaustedData();
                $prioritizeData['filter_interpretation'] = null;
                $prioritizeData['assumptions'] = null;
            } elseif ($items === []) {
                $emptyReasoning = trim((string) $selection['deterministic_summary']);
                $fallbackReasoning = $emptyReasoning !== '' ? $emptyReasoning : TaskAssistantListingDefaults::reasoningWhenEmpty();
                $prioritizeData = [
                    'items' => [],
                    'limit_used' => 0,
                    'doing_progress_coach' => null,
                    'focus' => [
                        'main_task' => 'No matching items found',
                        'secondary_tasks' => [],
                    ],
                    'acknowledgment' => null,
                    'framing' => TaskAssistantListingDefaults::clampBrowseReasoning(
                        $emptyReasoning !== ''
                            ? $emptyReasoning
                            : 'No matching items found. Here are next steps to refine your list.',
                    ),
                    'reasoning' => TaskAssistantListingDefaults::clampBrowseReasoning($fallbackReasoning),
                    'next_options' => TaskAssistantListingDefaults::clampBrowseReasoning('If you want, I can schedule these steps for later.'),
                    'next_options_chip_texts' => [
                        'Schedule these for later',
                        'Schedule these tasks for a specific time',
                    ],
                    'filter_interpretation' => null,
                    'assumptions' => null,
                ];
            } else {
                $narrative = $this->hybridNarrative->refinePrioritizeListing(
                    $promptData,
                    $content,
                    $variant,
                    $items,
                    $selection['deterministic_summary'],
                    $selection['filter_context_for_prompt'],
                    $selection['ambiguous'],
                    $thread->id,
                    $thread->user_id,
                );

                $next = $this->buildDeterministicPrioritizeNextOptions(
                    $narrative['items'] ?? [],
                    $hasMoreUnseen
                );

                $prioritizeData = [
                    'items' => $narrative['items'],
                    'limit_used' => count($narrative['items']),
                    'doing_progress_coach' => null,
                    'focus' => $narrative['focus'],
                    'acknowledgment' => $narrative['acknowledgment'] ?? null,
                    'framing' => (string) ($narrative['framing'] ?? ''),
                    'reasoning' => (string) ($narrative['reasoning'] ?? TaskAssistantListingDefaults::reasoningWhenEmpty()),
                    // Standardized follow-ups: deterministic and safe.
                    'next_options' => $next['next_options'],
                    'next_options_chip_texts' => $next['next_options_chip_texts'],
                    'filter_interpretation' => $narrative['filter_interpretation'] ?? null,
                    'assumptions' => $narrative['assumptions'] ?? null,
                ];
            }
        } else {
            $snapshot = $this->candidateProvider->candidatesForUser(
                $thread->user,
                taskLimit: 200,
            );
            $timezone = (string) ($snapshot['timezone'] ?? config('app.timezone', 'UTC'));
            $now = CarbonImmutable::now($timezone);

            $ranked = $this->prioritizationService->prioritizeFocus($snapshot, $context);
            Log::debug('task-assistant.prioritize.candidates', [
                'layer' => 'prioritize',
                'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
                'thread_id' => $thread->id,
                'assistant_message_id' => $assistantMessage->id,
                'tasks_count' => is_array($snapshot['tasks'] ?? null) ? count($snapshot['tasks']) : 0,
                'events_count' => is_array($snapshot['events'] ?? null) ? count($snapshot['events']) : 0,
                'projects_count' => is_array($snapshot['projects'] ?? null) ? count($snapshot['projects']) : 0,
                'entity_type_preference' => $context['entity_type_preference'] ?? null,
            ]);
            Log::debug('task-assistant.prioritize.ranked_top', [
                'layer' => 'prioritize',
                'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
                'thread_id' => $thread->id,
                'assistant_message_id' => $assistantMessage->id,
                'top' => array_slice(array_map(static fn (mixed $c): array => is_array($c) ? [
                    'type' => $c['type'] ?? null,
                    'id' => $c['id'] ?? null,
                    'score' => $c['score'] ?? null,
                    'title' => $c['title'] ?? null,
                ] : [], $ranked), 0, 10),
            ]);
            $isRankVariant = $variant === TaskAssistantPrioritizeVariant::Rank;
            $doingMeta = $isRankVariant
                ? $this->collectDoingTasksFromSnapshot($snapshot)
                : ['titles' => [], 'count' => 0];
            $doingProgressCoach = $isRankVariant
                ? TaskAssistantListingDefaults::buildDoingProgressCoach($doingMeta['titles'], $doingMeta['count'])
                : null;

            $allItems = [];

            foreach ($ranked as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }

                $type = (string) ($candidate['type'] ?? 'task');
                $id = (int) ($candidate['id'] ?? 0);
                $title = (string) ($candidate['title'] ?? 'Untitled');
                if ($id <= 0 || trim($title) === '') {
                    continue;
                }

                $raw = is_array($candidate['raw'] ?? null) ? $candidate['raw'] : [];

                if ($type === 'task') {
                    if ($isRankVariant && ($raw['status'] ?? '') === TaskStatus::Doing->value) {
                        continue;
                    }
                    $allItems[] = $this->buildPrioritizeListingTaskRowFromRawTask($raw, $id, $title, $now, $timezone);

                    continue;
                }

                $allItems[] = [
                    'entity_type' => $type,
                    'entity_id' => $id,
                    'title' => $title,
                ];
            }

            [$items, $hasMoreUnseen, $isExhaustedNextSlice] = $this->applyPrioritizeUnseenSlice(
                $allItems,
                $plan->countLimit,
                $seenEntityKeys,
                $isNextSliceFollowup
            );

            $promptData = $this->promptData->forUser($thread->user);
            $promptData['snapshot'] = $snapshot;
            $promptData['route_context'] = (string) config('task-assistant.listing_route_context', '');
            if ($isRankVariant && $doingMeta['count'] > 0) {
                $promptData['doing_context'] = [
                    'has_doing_tasks' => true,
                    'doing_titles' => array_slice($doingMeta['titles'], 0, 12),
                    'doing_count' => $doingMeta['count'],
                ];
            }

            $ambiguous = false;
            $deterministicSummary = $this->buildPrioritizeListingDeterministicSummary(count($items), $ambiguous);
            $filterContextForPrompt = $this->buildPrioritizeListingFilterContextForPrompt($ambiguous, $context);

            if ($isExhaustedNextSlice) {
                $prioritizeData = $this->buildPrioritizeExhaustedData();
                $prioritizeData['filter_interpretation'] = null;
                $prioritizeData['assumptions'] = null;
            } elseif ($items === [] && $isRankVariant && is_string($doingProgressCoach) && trim($doingProgressCoach) !== '') {
                $next = $this->buildDeterministicPrioritizeNextOptions([], $hasMoreUnseen);
                $prioritizeData = [
                    'items' => [],
                    'limit_used' => 0,
                    'doing_progress_coach' => $doingProgressCoach,
                    'focus' => [
                        'main_task' => (string) __('Wrap up in-progress work first'),
                        'secondary_tasks' => [],
                    ],
                    'acknowledgment' => null,
                    'framing' => TaskAssistantListingDefaults::clampFraming(
                        TaskAssistantListingDefaults::framingWhenRankSliceHasNoTodoButDoing()
                    ),
                    'reasoning' => TaskAssistantListingDefaults::clampBrowseReasoning(
                        (string) __('When you wrap up what you\'ve started, the next priorities will show up more clearly here.')
                    ),
                    'next_options' => $next['next_options'],
                    'next_options_chip_texts' => $next['next_options_chip_texts'],
                    'filter_interpretation' => null,
                    'assumptions' => null,
                ];
            } elseif ($items === []) {
                $emptyReasoning = trim((string) $deterministicSummary);
                $fallbackReasoning = $emptyReasoning !== '' ? $emptyReasoning : TaskAssistantListingDefaults::reasoningWhenEmpty();
                $prioritizeData = [
                    'items' => [],
                    'limit_used' => 0,
                    'doing_progress_coach' => null,
                    'focus' => [
                        'main_task' => 'No matching items found',
                        'secondary_tasks' => [],
                    ],
                    'acknowledgment' => null,
                    'framing' => TaskAssistantListingDefaults::clampBrowseReasoning(
                        $emptyReasoning !== ''
                            ? $emptyReasoning
                            : 'No matching items found. Here are next steps to refine your list.',
                    ),
                    'reasoning' => TaskAssistantListingDefaults::clampBrowseReasoning($fallbackReasoning),
                    'next_options' => TaskAssistantListingDefaults::clampBrowseReasoning('If you want, I can schedule these steps for later.'),
                    'next_options_chip_texts' => [
                        'Schedule these for later',
                        'Schedule these tasks for a specific time',
                    ],
                    'filter_interpretation' => null,
                    'assumptions' => null,
                ];
            } else {
                $narrative = $this->hybridNarrative->refinePrioritizeListing(
                    $promptData,
                    $content,
                    $variant,
                    $items,
                    $deterministicSummary,
                    $filterContextForPrompt,
                    $ambiguous,
                    $thread->id,
                    $thread->user_id,
                );

                $next = $this->buildDeterministicPrioritizeNextOptions(
                    $narrative['items'] ?? [],
                    $hasMoreUnseen
                );

                $prioritizeData = [
                    'items' => $narrative['items'],
                    'limit_used' => count($narrative['items']),
                    'doing_progress_coach' => $doingProgressCoach,
                    'focus' => $narrative['focus'],
                    'acknowledgment' => $narrative['acknowledgment'] ?? null,
                    'framing' => (string) ($narrative['framing'] ?? ''),
                    'reasoning' => (string) ($narrative['reasoning'] ?? TaskAssistantListingDefaults::reasoningWhenEmpty()),
                    // Standardized follow-ups: deterministic and safe.
                    'next_options' => $next['next_options'],
                    'next_options_chip_texts' => $next['next_options_chip_texts'],
                    'filter_interpretation' => $narrative['filter_interpretation'] ?? null,
                    'assumptions' => $narrative['assumptions'] ?? null,
                ];
            }
        }

        $this->attachPrioritizeOrchestrationMetadata($prioritizeData, $plan);

        $generationResult = [
            'valid' => true,
            'data' => $prioritizeData,
            'errors' => [],
        ];

        $execution = $this->flowExecutionEngine->executeStructuredFlow(
            flow: 'prioritize',
            metadataKey: 'prioritize',
            thread: $thread,
            assistantMessage: $assistantMessage,
            generationResult: $generationResult,
            assistantFallbackContent: 'I could not build a task list yet. Try again with a bit more detail.'
        );

        $finalListingItems = is_array($prioritizeData['items'] ?? null) ? $prioritizeData['items'] : [];

        if ($finalListingItems === []) {
            if (! $isNextSliceFollowup) {
                $this->conversationState->clearLastListing($thread);
            }
        } else {
            $this->conversationState->rememberLastListing(
                $thread,
                'prioritize',
                $finalListingItems,
                $assistantMessage->id,
                count($finalListingItems),
                $useBrowseEngine ? 'browse' : 'rank',
            );
            $this->conversationState->rememberPrioritizeShownEntities($thread, $finalListingItems);
        }

        $this->streamFlowEnvelope(
            thread: $thread,
            assistantMessage: $assistantMessage,
            flow: 'prioritize',
            execution: $execution
        );
    }

    private function resolvePrioritizeFollowupListingEngine(TaskAssistantThread $thread): string
    {
        $listing = $this->conversationState->lastListing($thread);
        $engine = is_array($listing) ? ($listing['prioritize_engine'] ?? null) : null;
        $engine = is_string($engine) ? strtolower(trim($engine)) : '';

        return $engine === 'browse' ? 'browse' : 'rank';
    }

    /**
     * @param  array<string, mixed>  $prioritizeData
     */
    private function attachPrioritizeOrchestrationMetadata(array &$prioritizeData, ExecutionPlan $plan): void
    {
        $prioritizeData['prioritize_variant'] = ($plan->prioritizeVariant ?? TaskAssistantPrioritizeVariant::Rank)->value;
    }

    /**
     * @param  list<array<string, mixed>>  $allItems
     * @param  list<string>  $seenEntityKeys
     * @return array{0: list<array<string, mixed>>, 1: bool, 2: bool}
     */
    private function applyPrioritizeUnseenSlice(array $allItems, int $countLimit, array $seenEntityKeys, bool $followup): array
    {
        $limit = max(1, min($countLimit, 10));

        if (! $followup) {
            $slice = array_values(array_slice($allItems, 0, $limit));

            return [$slice, count($allItems) > count($slice), false];
        }

        $seenLookup = [];
        foreach ($seenEntityKeys as $key) {
            $normalized = trim((string) $key);
            if ($normalized !== '') {
                $seenLookup[$normalized] = true;
            }
        }

        $unseen = [];
        foreach ($allItems as $item) {
            if (! is_array($item)) {
                continue;
            }
            $entityKey = $this->prioritizeEntityKeyFromItem($item);
            if ($entityKey === null || isset($seenLookup[$entityKey])) {
                continue;
            }
            $unseen[] = $item;
        }

        if ($unseen === []) {
            return [[], false, true];
        }

        $slice = array_values(array_slice($unseen, 0, $limit));

        return [$slice, count($unseen) > count($slice), false];
    }

    /**
     * For prioritize follow-ups like "show next 3", keep the same entity-type preference as the
     * previous prioritize listing unless the user explicitly changes it in the new message.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function inheritEntityTypePreferenceForPrioritizeFollowup(TaskAssistantThread $thread, array $context): array
    {
        $preference = (string) ($context['entity_type_preference'] ?? 'mixed');
        if ($preference !== '' && $preference !== 'mixed') {
            return $context;
        }

        $lastListing = $this->conversationState->lastListing($thread);
        $sourceFlow = is_array($lastListing) ? (string) ($lastListing['source_flow'] ?? '') : '';
        $items = is_array($lastListing) ? ($lastListing['items'] ?? null) : null;

        if ($sourceFlow !== 'prioritize' || ! is_array($items) || $items === []) {
            return $context;
        }

        $counts = ['task' => 0, 'event' => 0, 'project' => 0];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $type = strtolower(trim((string) ($item['entity_type'] ?? '')));
            if (isset($counts[$type])) {
                $counts[$type]++;
            }
        }

        $nonZero = array_values(array_filter($counts, static fn (int $n): bool => $n > 0));
        if (count($nonZero) !== 1) {
            return $context;
        }

        $inherited = (string) array_key_first(array_filter($counts, static fn (int $n): bool => $n > 0));
        if (! in_array($inherited, ['task', 'event', 'project'], true)) {
            return $context;
        }

        Log::info('task-assistant.prioritize.followup_inherit_preference', [
            'layer' => 'prioritize',
            'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
            'thread_id' => $thread->id,
            'assistant_message_id' => app()->bound('task_assistant.message_id') ? app('task_assistant.message_id') : null,
            'inherited_entity_type_preference' => $inherited,
        ]);

        $context['entity_type_preference'] = $inherited;

        return $context;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function prioritizeEntityKeyFromItem(array $item): ?string
    {
        $type = strtolower(trim((string) ($item['entity_type'] ?? '')));
        $id = (int) ($item['entity_id'] ?? 0);
        if ($type === '' || $id <= 0) {
            return null;
        }

        return $type.':'.$id;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{titles: list<string>, count: int}
     */
    private function collectDoingTasksFromSnapshot(array $snapshot): array
    {
        $tasks = is_array($snapshot['tasks'] ?? null) ? $snapshot['tasks'] : [];
        $titles = [];
        $seenIds = [];

        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            if (($task['status'] ?? '') !== TaskStatus::Doing->value) {
                continue;
            }
            $id = (int) ($task['id'] ?? 0);
            $title = trim((string) ($task['title'] ?? ''));
            if ($id <= 0 || $title === '') {
                continue;
            }
            if (isset($seenIds[$id])) {
                continue;
            }
            $seenIds[$id] = true;
            $titles[] = $title;
        }

        return [
            'titles' => $titles,
            'count' => count($titles),
        ];
    }

    private function buildPrioritizeExhaustedData(): array
    {
        return [
            'items' => [],
            'limit_used' => 0,
            'doing_progress_coach' => null,
            'focus' => [
                'main_task' => 'No more unseen priorities',
                'secondary_tasks' => [],
            ],
            'acknowledgment' => null,
            'framing' => TaskAssistantListingDefaults::clampFraming(
                'You are caught up on the unseen priorities from this list.'
            ),
            'reasoning' => TaskAssistantListingDefaults::clampBrowseReasoning(
                'I have already shown the highest-ranked unseen items from your current priorities.'
            ),
            'next_options' => TaskAssistantListingDefaults::clampNextField(
                'If you want, I can schedule these tasks, or refine this list with a filter like today, this week, or by keyword.'
            ),
            'next_options_chip_texts' => [
                'Schedule tasks',
                'Refine list',
            ],
        ];
    }

    /**
     * Standardize prioritize follow-up options (text + chips) to avoid odd model outputs.
     *
     * @return array{next_options: string, next_options_chip_texts: list<string>}
     */
    private function buildDeterministicPrioritizeNextOptions(mixed $items, bool $hasMoreUnseen = true): array
    {
        $rows = is_array($items) ? array_values(array_filter($items, static fn (mixed $r): bool => is_array($r))) : [];
        $count = count($rows);

        $windowChips = [
            'Later today',
            'Tomorrow',
            'This week',
        ];

        if (! $hasMoreUnseen) {
            $nextOptions = 'You are caught up on the unseen priorities from this list. If you want, I can schedule these tasks for later today, tomorrow, or this week.';

            return [
                'next_options' => TaskAssistantListingDefaults::clampNextField($nextOptions),
                'next_options_chip_texts' => $windowChips,
            ];
        }

        if ($count <= 1) {
            $nextOptions = 'If you want, I can schedule this task for later today, tomorrow, or this week.';

            return [
                'next_options' => TaskAssistantListingDefaults::clampNextField($nextOptions),
                'next_options_chip_texts' => $windowChips,
            ];
        }

        $nextOptions = 'If you want, I can schedule these tasks for later today, tomorrow, or this week.';

        return [
            'next_options' => TaskAssistantListingDefaults::clampNextField($nextOptions),
            'next_options_chip_texts' => $windowChips,
        ];
    }

    /**
     * Human-readable time line for prioritize narrative prompts (aligned with {@see TaskPrioritizationService::applyTimeConstraintFilter}).
     */
    private function formatTimeConstraintForPrioritizePrompt(?string $timeConstraint): ?string
    {
        if ($timeConstraint === null || $timeConstraint === '') {
            return null;
        }

        $tc = (string) $timeConstraint;

        return $tc === 'today'
            ? 'time: today (includes overdue and anything due today)'
            : 'time: '.$tc;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function buildPrioritizeListingFilterContextForPrompt(bool $ambiguous, array $context): string
    {
        if ($ambiguous) {
            return 'no strong filters; showing top-ranked tasks for now';
        }

        if (($context['domain_focus'] ?? null) === 'school') {
            $parts = [];
            $timeLine = $this->formatTimeConstraintForPrioritizePrompt(
                is_string($context['time_constraint'] ?? null) ? (string) $context['time_constraint'] : null
            );
            if ($timeLine !== null) {
                $parts[] = $timeLine;
            }
            if (! empty($context['task_keywords'] ?? [])) {
                $parts[] = 'keywords/tags/title: '.implode(', ', $context['task_keywords']);
            }
            $domainLine = 'domain: school (subjects, teachers, and academic tags — not generic errands)';
            if ($parts === []) {
                return $domainLine;
            }

            return $domainLine.'; '.implode('; ', $parts);
        }

        if (($context['domain_focus'] ?? null) === 'chores') {
            $parts = [];
            $timeLine = $this->formatTimeConstraintForPrioritizePrompt(
                is_string($context['time_constraint'] ?? null) ? (string) $context['time_constraint'] : null
            );
            if ($timeLine !== null) {
                $parts[] = $timeLine;
            }
            if (! empty($context['task_keywords'] ?? [])) {
                $parts[] = 'keywords/tags/title: '.implode(', ', $context['task_keywords']);
            }
            $domainLine = 'domain: chores / household (prefers recurring when available)';
            if ($parts === []) {
                return $domainLine;
            }

            return $domainLine.'; '.implode('; ', $parts);
        }

        $parts = [];
        if (! empty($context['recurring_requested'])) {
            $parts[] = 'recurring tasks only';
        }
        $timeLine = $this->formatTimeConstraintForPrioritizePrompt(
            is_string($context['time_constraint'] ?? null) ? (string) $context['time_constraint'] : null
        );
        if ($timeLine !== null) {
            $parts[] = $timeLine;
        }
        if (! empty($context['priority_filters'] ?? [])) {
            $parts[] = 'priority: '.implode(',', $context['priority_filters']);
        }
        if (! empty($context['task_keywords'] ?? [])) {
            $parts[] = 'keywords/tags/title: '.implode(', ', $context['task_keywords']);
        }

        return $parts === []
            ? 'all matching tasks in your list (ranked by urgency)'
            : implode('; ', $parts);
    }

    private function buildPrioritizeListingDeterministicSummary(int $count, bool $ambiguous): string
    {
        if ($count === 0) {
            return (string) __('Nothing matched that request yet—try widening filters or adding a task.');
        }

        if ($ambiguous) {
            return (string) __('Here are :count tasks from your list, ordered by urgency and due dates:', ['count' => $count]);
        }

        return $count === 1
            ? (string) __('Here’s one task that fits this request—ordered by urgency.')
            : (string) __('Here are :count tasks that fit this request—ordered by urgency.', ['count' => $count]);
    }

    /**
     * @param  array<string, mixed>  $rawTask
     * @return array<string, mixed>
     */
    private function buildPrioritizeListingTaskRowFromRawTask(array $rawTask, int $id, string $title, CarbonImmutable $now, string $timezone): array
    {
        $dueBucket = 'no_deadline';
        $dueOn = '—';
        $duePhrase = 'no due date';

        $deadline = null;
        if (isset($rawTask['ends_at']) && $rawTask['ends_at'] !== null && trim((string) $rawTask['ends_at']) !== '') {
            try {
                $deadline = CarbonImmutable::parse((string) $rawTask['ends_at'], $timezone);
            } catch (\Throwable) {
                $deadline = null;
            }
        }

        if ($deadline !== null) {
            $startOfToday = $now->startOfDay();
            if ($deadline->lt($startOfToday)) {
                $dueBucket = 'overdue';
            } elseif ($deadline->isSameDay($now)) {
                $dueBucket = 'due_today';
            } else {
                $tomorrow = $now->addDay();
                if ($deadline->isSameDay($tomorrow)) {
                    $dueBucket = 'due_tomorrow';
                } elseif ($deadline->lte($now->addDays(7))) {
                    $dueBucket = 'due_this_week';
                } else {
                    $dueBucket = 'due_later';
                }
            }

            $dueOn = $deadline
                ->locale((string) config('app.locale', 'en'))
                ->translatedFormat('M j, Y');

            $duePhrase = match ($dueBucket) {
                'overdue' => 'overdue',
                'due_today' => 'due today',
                'due_tomorrow' => 'due tomorrow',
                'due_this_week' => 'due this week',
                'due_later' => 'due later',
                default => 'scheduled',
            };
        }

        $priority = strtolower(trim((string) ($rawTask['priority'] ?? 'medium')));

        $complexityRaw = $rawTask['complexity'] ?? null;
        $complexityLabel = TaskAssistantListingDefaults::complexityNotSetLabel();
        if (is_string($complexityRaw) && $complexityRaw !== '') {
            $complexityEnum = TaskComplexity::tryFrom($complexityRaw);
            if ($complexityEnum !== null) {
                $complexityLabel = $complexityEnum->label();
            }
        }

        return [
            'entity_type' => 'task',
            'entity_id' => $id,
            'title' => $title,
            'priority' => $priority,
            'due_bucket' => $dueBucket,
            'due_phrase' => $duePhrase,
            'due_on' => $dueOn,
            'complexity_label' => $complexityLabel,
        ];
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
            'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
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

    private function runGeneralGuidanceFlow(
        TaskAssistantThread $thread,
        TaskAssistantMessage $assistantMessage,
        string $userMessage,
        ExecutionPlan $plan,
    ): void {
        Log::info('task-assistant.flow', [
            'layer' => 'flow',
            'flow' => 'general_guidance',
            'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessage->id,
        ]);

        if (in_array('intent_off_topic', $plan->reasonCodes, true)) {
            // Strong guardrail to keep Hermes in the task assistant domain even
            // when users ask unrelated questions (relationships, politics, product
            // recommendations, etc.). We still require the general_guidance schema.
            $userMessage .= "\n\nOFF_TOPIC_GUARDRAIL: This request is off-topic for a task assistant. Acknowledge briefly, refuse to help with the unrelated topic, and suggest task-focused next steps (prioritize tasks or schedule time blocks) while following the current general_guidance schema.";
        }

        if (in_array('general_guidance_greeting_only', $plan->reasonCodes, true)) {
            // Greeting-only prompts should not assume tasks exist or pull list details.
            $userMessage .= "\n\nGREETING_GUARDRAIL: The user only greeted (hello/hi/yo). Do not assume they want task suggestions yet. Do not reference their list data, deadlines, priorities, or specific task titles. Introduce TaskLyst, say you can prioritize tasks or schedule time blocks, and offer neutral next actions.";
        }

        $guidance = $this->generalGuidanceService->generateGeneralGuidance(
            user: $thread->user,
            userMessage: $userMessage,
            forcedMode: in_array('intent_off_topic', $plan->reasonCodes, true) ? 'off_topic' : null,
        );

        $state = $this->conversationState->get($thread);

        // Add a deterministic "intro" the first time we respond with
        // general_guidance in this thread.
        $shouldIntroduce = ! (bool) ($state['general_guidance_intro_done'] ?? false);
        if ($shouldIntroduce) {
            $intro = "Hi, I'm TaskLyst—your task assistant.";
            $currentAck = trim((string) ($guidance['acknowledgement'] ?? ''));

            if ($currentAck !== '' && ! str_starts_with($currentAck, $intro)) {
                $currentAck = $intro.' '.$currentAck;
            }

            // If the model still adds another greeting right after the intro,
            // remove the second greeting sentence to keep things non-repetitive.
            $currentAck = preg_replace(
                '/^(Hi, I\'m TaskLyst—your task assistant\.)\s+(hi|hello|hey)\b[^.!?]*[.!?]\s*/iu',
                '$1 ',
                (string) $currentAck
            ) ?: $currentAck;

            // Keep within general guidance validation bounds.
            if (mb_strlen($currentAck) > 220) {
                $currentAck = mb_substr($currentAck, 0, 220);
            }

            $guidance['acknowledgement'] = $currentAck;

            $state['general_guidance_intro_done'] = true;
            $this->conversationState->put($thread, $state);
        }

        $this->conversationState->put($thread, $state);

        $generationResult = [
            'valid' => true,
            'data' => [
                'intent' => (string) ($guidance['intent'] ?? 'task'),
                'acknowledgement' => (string) ($guidance['acknowledgement'] ?? ''),
                'message' => (string) ($guidance['message'] ?? ''),
                'suggested_next_actions' => is_array($guidance['suggested_next_actions'] ?? null)
                    ? $guidance['suggested_next_actions']
                    : null,
            ],
            'errors' => [],
        ];

        $execution = $this->flowExecutionEngine->executeStructuredFlow(
            flow: 'general_guidance',
            metadataKey: 'general_guidance',
            thread: $thread,
            assistantMessage: $assistantMessage,
            generationResult: $generationResult,
            assistantFallbackContent: "Hi, I'm TaskLyst—your task assistant. Would you like me to prioritize your tasks or schedule time blocks for them?",
        );

        $intent = (string) ($guidance['intent'] ?? 'task');
        $suggestedNextActions = is_array($guidance['suggested_next_actions'] ?? null)
            ? $guidance['suggested_next_actions']
            : [];

        Log::info('task-assistant.general_guidance.telemetry', [
            'layer' => 'flow',
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessage->id,
            'intent' => $intent,
            'suggested_next_actions_count' => count($suggestedNextActions),
        ]);
        $this->conversationState->clearPendingGeneralGuidance($thread);

        $this->streamFlowEnvelope(
            thread: $thread,
            assistantMessage: $assistantMessage,
            flow: 'general_guidance',
            execution: $execution
        );
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
            envelope: $envelope
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
        $structuredData = is_array($execution['structured_data'] ?? null) ? $execution['structured_data'] : [];
        Log::debug('task-assistant.stream_flow', [
            'layer' => 'orchestration',
            'stage' => 'before_stream_envelope',
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessage->id,
            'flow' => $flow,
            'assistant_content_length' => mb_strlen((string) ($assistantMessage->content ?? '')),
            'structured_data_keys' => array_keys($structuredData),
            'final_valid' => (bool) ($execution['final_valid'] ?? false),
        ]);

        $this->streamFinalAssistantJson(
            $thread->user_id,
            $assistantMessage,
            $this->buildJsonEnvelope(
                flow: $flow,
                data: $structuredData,
                threadId: $thread->id,
                assistantMessageId: $assistantMessage->id,
                ok: (bool) ($execution['final_valid'] ?? false),
            )
        );

        Log::debug('task-assistant.stream_flow', [
            'layer' => 'orchestration',
            'stage' => 'after_stream_envelope',
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessage->id,
            'flow' => $flow,
        ]);
    }

    /**
     * Builds the execution plan using the application intent pipeline (no alternate router).
     */
    private function buildExecutionPlan(TaskAssistantThread $thread, string $content): ExecutionPlan
    {
        $decision = $this->routingPolicy->decide($thread, $content);
        $constraints = $decision->constraints;
        $flow = match ($decision->flow) {
            'prioritize',
            'schedule',
            'general_guidance' => $decision->flow,
            default => throw new \UnexpectedValueException('Unsupported routing flow: '.$decision->flow),
        };
        $countLimit = max(1, min((int) ($constraints['count_limit'] ?? 3), 10));
        $timeWindowHint = is_string($constraints['time_window_hint'] ?? null) ? $constraints['time_window_hint'] : null;
        $targetEntities = is_array($constraints['target_entities'] ?? null) ? $constraints['target_entities'] : [];

        $generationProfile = match ($flow) {
            'schedule' => 'schedule',
            'prioritize' => 'prioritize',
            'general_guidance' => 'general_guidance',
        };

        $prioritizeVariant = null;
        if ($flow === 'prioritize') {
            $resolution = $this->prioritizeVariantResolver->resolve(
                $thread,
                $content,
                $constraints,
                $decision->reasonCodes,
            );
            $prioritizeVariant = $resolution->variant;

            Log::debug('task-assistant.prioritize.variant_resolution', [
                'layer' => 'prioritize',
                'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
                'thread_id' => $thread->id,
                'prioritize_variant' => $prioritizeVariant->value,
                'resolution_confidence' => $resolution->confidence,
                'used_classifier' => $resolution->usedClassifier,
            ]);
        }

        return new ExecutionPlan(
            flow: $flow,
            confidence: $decision->confidence,
            clarificationNeeded: false,
            clarificationQuestion: null,
            reasonCodes: $decision->reasonCodes,
            constraints: $constraints,
            targetEntities: $targetEntities,
            timeWindowHint: $timeWindowHint,
            countLimit: $countLimit,
            generationProfile: $generationProfile,
            prioritizeVariant: $prioritizeVariant,
        );
    }

    private function logRoutingDecision(TaskAssistantThread $thread, TaskAssistantMessage $assistantMessage, ExecutionPlan $plan): void
    {
        Log::info('task-assistant.routing_decision', [
            'layer' => 'routing',
            'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessage->id,
            'flow' => $plan->flow,
            'confidence' => $plan->confidence,
            'clarification_needed' => false,
            'reason_codes' => $plan->reasonCodes,
            'target_entities_count' => count($plan->targetEntities),
            'time_window_hint' => $plan->timeWindowHint,
            'count_limit' => $plan->countLimit,
            'generation_profile' => $plan->generationProfile,
            'prioritize_variant' => $plan->flow === 'prioritize' ? $plan->prioritizeVariant?->value : null,
            'intent_use_llm' => (bool) config('task-assistant.intent.use_llm', true),
        ]);
    }

    private function previewForLogs(string $text, int $maxChars = 120): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($value === '') {
            return '';
        }

        if (mb_strlen($value) <= $maxChars) {
            return $value;
        }

        return mb_substr($value, 0, $maxChars).'…';
    }

    private function isCancellationRequested(TaskAssistantThread $thread, int $assistantMessageId): bool
    {
        $assistantMessage = TaskAssistantMessage::query()
            ->where('thread_id', $thread->id)
            ->where('id', $assistantMessageId)
            ->where('role', MessageRole::Assistant)
            ->first();

        if (! $assistantMessage) {
            return false;
        }

        return data_get($assistantMessage->metadata, 'stream.status') === 'stopped';
    }

    private function looksLikeStandaloneGeneralGuidancePrompt(string $content): bool
    {
        $normalized = mb_strtolower(trim($content));
        if ($normalized === '') {
            return true;
        }

        if (preg_match(
            '/^(hi|hii|hello|hey|yo|good morning|good afternoon|good evening|howdy|gm|hiya)(\s+(there|yo))?([!?.]|\s)*$/u',
            $normalized
        ) === 1) {
            return true;
        }

        if (preg_match(
            '/\b(current\s+time|time\s+now|time\s+right\s+now|what\s+time\s+is\s+it|what\s*\'?s\s+the\s+time|date\s+today|today\s*\'?s\s+date|what\s+date\s+is\s+it|what\s*\'?s\s+the\s+date)\b/u',
            $normalized
        ) === 1) {
            return true;
        }

        // Single-token gibberish signal.
        if (! str_contains($normalized, ' ')
            && mb_strlen($normalized) >= 9
            && preg_match('/^[a-z0-9]+$/u', $normalized) === 1
        ) {
            $commonBigrams = ['th', 'he', 'in', 'er', 'an', 're', 'on', 'at', 'en', 'nd', 'ti', 'es', 'or', 'te', 'of'];
            $hasCommonBigram = false;
            foreach ($commonBigrams as $bigram) {
                if (mb_stripos($normalized, $bigram) !== false) {
                    $hasCommonBigram = true;
                    break;
                }
            }
            if (! $hasCommonBigram) {
                return true;
            }
        }

        $offTopicMarkers = [
            'best ', 'who is', 'why he', 'why she', 'relationship', 'politics', 'president',
            'shoes', 'cook', 'martial artist', 'love me',
        ];
        foreach ($offTopicMarkers as $marker) {
            if (mb_stripos($normalized, $marker) !== false) {
                return true;
            }
        }

        $emotionalMarkers = ['sad', 'heartbroken', 'broke up', 'cry', 'depressed', 'devastated', 'partner left me'];
        $taskKeywords = ['task', 'tasks', 'prioritize', 'priority', 'schedule', 'time block', 'work on', 'to do', 'todo', 'list'];
        foreach ($emotionalMarkers as $marker) {
            if (mb_stripos($normalized, $marker) === false) {
                continue;
            }

            $hasTaskKeyword = false;
            foreach ($taskKeywords as $keyword) {
                if (mb_stripos($normalized, $keyword) !== false) {
                    $hasTaskKeyword = true;
                    break;
                }
            }

            if (! $hasTaskKeyword) {
                return true;
            }
        }

        return false;
    }

    private function markCancelled(TaskAssistantThread $thread, int $assistantMessageId): void
    {
        $assistantMessage = TaskAssistantMessage::query()
            ->where('thread_id', $thread->id)
            ->where('id', $assistantMessageId)
            ->where('role', MessageRole::Assistant)
            ->first();

        if ($assistantMessage) {
            $metadata = is_array($assistantMessage->metadata ?? null) ? $assistantMessage->metadata : [];
            data_set($metadata, 'stream.status', 'stopped');
            data_set($metadata, 'stream.stopped_at', now()->toIso8601String());
            $assistantMessage->update([
                'content' => '',
                'metadata' => $metadata,
            ]);
        }

        $threadMetadata = is_array($thread->metadata ?? null) ? $thread->metadata : [];
        data_set($threadMetadata, 'stream.processing', null);
        data_set($threadMetadata, 'stream.last_completed_at', now()->toIso8601String());
        $thread->update(['metadata' => $threadMetadata]);
    }
}
