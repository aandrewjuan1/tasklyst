<?php

namespace App\Services\LLM\TaskAssistant;

use App\Enums\MessageRole;
use App\Enums\TaskComplexity;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use App\Services\LLM\Browse\TaskAssistantListingSelectionService;
use App\Services\LLM\Prioritization\TaskAssistantTaskChoiceConstraintsExtractor;
use App\Services\LLM\Prioritization\TaskPrioritizationService;
use App\Services\LLM\Scheduling\TaskAssistantStructuredFlowGenerator;
use App\Support\LLM\TaskAssistantListingDefaults;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
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
        private readonly TaskAssistantConversationStateService $conversationState,
        private readonly TaskAssistantGeneralGuidanceService $generalGuidanceService,
        private readonly IntentRoutingPolicy $routingPolicy,
        private readonly TaskAssistantHybridNarrativeService $hybridNarrative,
        private readonly TaskAssistantListingSelectionService $listingSelectionService,
    ) {}

    public function processQueuedMessage(TaskAssistantThread $thread, int $userMessageId, int $assistantMessageId): void
    {
        if ($this->isCancellationRequested($thread, $assistantMessageId)) {
            Log::info('task-assistant.orchestration', [
                'layer' => 'orchestration',
                'stage' => 'cancelled_before_processing',
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
                'thread_id' => $thread->id,
                'user_id' => $thread->user_id,
                'user_message_id' => $userMessageId,
                'assistant_message_id' => $assistantMessageId,
            ]);

            $content = (string) ($userMessage->content ?? '');

            // If we previously asked a clarification question, resolve the implied
            // branch and forced constraints from the user's answer.
            $pendingClarification = $this->conversationState->pendingClarification($thread);
            if ($pendingClarification !== null) {
                $pendingTargetFlow = (string) ($pendingClarification['target_flow'] ?? '');
                $reasonCodes = is_array($pendingClarification['reason_codes'] ?? null)
                    ? $pendingClarification['reason_codes']
                    : [];

                $answer = mb_strtolower(trim($content));

                $scheduleIntent = preg_match(
                    '/\b(schedule|calendar|time\s*block|time\s*slot|time\s*blocking|plan\s*my\s*day|daily\s*plan|when\s*should\s*i\s*work)\b/i',
                    $answer
                ) === 1;

                $freshPlanIntent = preg_match(
                    '/\b(whole\s*day|entire\s*day|all\s*day|fresh\s*plan|new\s*plan|schedule\s*everything|plan\s*the\s*day)\b/i',
                    $answer
                ) === 1;

                $selectedTasksIntent = preg_match(
                    '/\b(those|them|these|selected|top\s*tasks|those\s*\d+|them\s*\d+)\b/i',
                    $answer
                ) === 1;

                $forcedFlow = null;
                $constraints = null;
                $targetEntitiesOverride = null;

                if ($pendingTargetFlow === 'prioritize') {
                    if ($scheduleIntent) {
                        $forcedFlow = 'schedule';
                        $constraints = $this->routingPolicy->extractConstraintsForFlow(
                            $thread,
                            $content,
                            $forcedFlow
                        );

                        // The question context implies "schedule them" refers to the
                        // existing prioritize selection.
                        $existingTargets = is_array($constraints['target_entities'] ?? null)
                            ? $constraints['target_entities']
                            : [];
                        $selectedEntities = $this->conversationState->selectedEntities($thread);
                        $targetEntitiesOverride = $existingTargets === [] && $selectedEntities !== []
                            ? $selectedEntities
                            : null;
                    } else {
                        $forcedFlow = 'prioritize';
                        $constraints = $this->routingPolicy->extractConstraintsForFlow(
                            $thread,
                            $content,
                            $forcedFlow
                        );
                    }
                } elseif ($pendingTargetFlow === 'schedule') {
                    $forcedFlow = 'schedule';
                    $constraints = $this->routingPolicy->extractConstraintsForFlow(
                        $thread,
                        $content,
                        $forcedFlow
                    );

                    if ($freshPlanIntent) {
                        // "Fresh plan / whole day" means "don't schedule only the selected ones".
                        $targetEntitiesOverride = [];
                    } elseif ($selectedTasksIntent) {
                        // "Selected tasks" means keep/derive targets from the previous selection.
                        $existingTargets = is_array($constraints['target_entities'] ?? null)
                            ? $constraints['target_entities']
                            : [];
                        $selectedEntities = $this->conversationState->selectedEntities($thread);
                        $targetEntitiesOverride = $existingTargets === [] && $selectedEntities !== []
                            ? $selectedEntities
                            : null;
                    } else {
                        // Ambiguous answer: fall back to normal routing, but clear pending state
                        // so we do not loop on every message.
                        $this->conversationState->clearPendingClarification($thread);
                        $pendingClarification = null;
                    }
                }

                if ($pendingClarification !== null && $forcedFlow !== null && is_array($constraints)) {
                    if (is_array($targetEntitiesOverride)) {
                        $constraints['target_entities'] = $targetEntitiesOverride;
                    }

                    $minConfidence = 0.75;
                    $countLimit = max(1, min((int) ($constraints['count_limit'] ?? 3), 10));
                    $timeWindowHint = is_string($constraints['time_window_hint'] ?? null)
                        ? $constraints['time_window_hint']
                        : null;
                    $targetEntities = is_array($constraints['target_entities'] ?? null)
                        ? $constraints['target_entities']
                        : [];

                    $finalConfidence = max($minConfidence, 0.85);
                    $reasonCodes = array_values(array_unique(array_merge(
                        $reasonCodes,
                        ['clarification_enforced_'.$forcedFlow]
                    )));

                    $forcedPlan = new ExecutionPlan(
                        flow: $forcedFlow,
                        confidence: $finalConfidence,
                        clarificationNeeded: false,
                        clarificationQuestion: null,
                        reasonCodes: $reasonCodes,
                        constraints: $constraints,
                        targetEntities: $targetEntities,
                        timeWindowHint: $timeWindowHint,
                        countLimit: $countLimit,
                        generationProfile: $forcedFlow === 'schedule' ? 'schedule' : 'prioritize',
                    );

                    $this->conversationState->clearPendingClarification($thread);

                    if ($forcedFlow === 'prioritize') {
                        $this->runPrioritizeFlow($thread, $assistantMessage, $content, $forcedPlan);

                        return;
                    }

                    $this->runScheduleFlow($thread, $userMessage, $assistantMessage, $content, $forcedPlan);

                    return;
                }
            }

            // If we previously asked a guidance question, resolve prioritize vs
            // schedule from the user's answer, instead of re-running normal routing.
            $pending = $this->conversationState->pendingGeneralGuidance($thread);
            if ($pending !== null) {
                $targetResolution = $this->generalGuidanceService->resolveTargetFromAnswer(
                    $thread->user,
                    $pending['clarifying_question'],
                    $content
                );

                $minConfidence = 0.55;
                $target = (string) ($targetResolution['target'] ?? 'either');
                $confidence = (float) ($targetResolution['confidence'] ?? 0.0);

                $decisionForTarget = $target !== 'either' && $confidence >= $minConfidence;
                if (! $decisionForTarget) {
                    $this->runGeneralGuidanceFlow(
                        thread: $thread,
                        assistantMessage: $assistantMessage,
                        userMessage: $pending['initial_user_message'],
                        plan: new ExecutionPlan(
                            flow: 'general_guidance',
                            confidence: $confidence,
                            clarificationNeeded: false,
                            clarificationQuestion: null,
                            reasonCodes: $pending['reason_codes'],
                            constraints: [],
                            targetEntities: [],
                            timeWindowHint: null,
                            countLimit: 3,
                            generationProfile: 'general_guidance',
                        ),
                        forcedClarifyingQuestion: $pending['clarifying_question'],
                    );

                    return;
                }

                $forcedFlow = $target === 'schedule' ? 'schedule' : 'prioritize';

                // Extract constraints for the forced flow. We do not want a second
                // "full routing decision" that could disagree with the guidance target.
                $constraints = $this->routingPolicy->extractConstraintsForFlow(
                    $thread,
                    $content,
                    $forcedFlow
                );
                $countLimit = max(1, min((int) ($constraints['count_limit'] ?? 3), 10));
                $timeWindowHint = is_string($constraints['time_window_hint'] ?? null) ? $constraints['time_window_hint'] : null;
                $targetEntities = is_array($constraints['target_entities'] ?? null) ? $constraints['target_entities'] : [];

                $finalConfidence = $confidence;
                $reasonCodes = array_values(array_unique(array_merge(
                    $pending['reason_codes'],
                    ['general_guidance_target_'.$forcedFlow],
                )));

                $forcedPlan = new ExecutionPlan(
                    flow: $forcedFlow,
                    confidence: $finalConfidence,
                    clarificationNeeded: false,
                    clarificationQuestion: null,
                    reasonCodes: $reasonCodes,
                    constraints: $constraints,
                    targetEntities: $targetEntities,
                    timeWindowHint: $timeWindowHint,
                    countLimit: $countLimit,
                    generationProfile: $forcedFlow === 'schedule' ? 'schedule' : 'prioritize',
                );

                $this->conversationState->clearPendingGeneralGuidance($thread);

                if ($forcedFlow === 'prioritize') {
                    $this->runPrioritizeFlow($thread, $assistantMessage, $content, $forcedPlan);

                    return;
                }

                $this->runScheduleFlow($thread, $userMessage, $assistantMessage, $content, $forcedPlan);

                return;
            }

            $plan = $this->buildExecutionPlan($thread, $content);
            $this->logRoutingDecision($thread, $assistantMessage, $plan);

            if ($plan->clarificationNeeded) {
                $this->runClarificationFlow($thread, $assistantMessage, $plan);

                return;
            }

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

        $context = $this->constraintsExtractor->extract($content);
        $prioritizeTaskListMode = $this->shouldUsePrioritizeTaskListMode($plan, $content);
        $isNextSliceFollowup = $this->isPrioritizeNextSliceFollowup($thread, $plan, $content);
        $seenEntityKeys = $isNextSliceFollowup
            ? $this->conversationState->prioritizeShownEntityKeys($thread)
            : [];

        if (! $isNextSliceFollowup) {
            $this->conversationState->clearPrioritizePagination($thread);
        }

        $items = [];
        $prioritizeData = [];

        if ($prioritizeTaskListMode) {
            $taskLimit = max(1, (int) config('task-assistant.listing.snapshot_task_limit', 200));
            $snapshot = $this->snapshotService->buildForUser($thread->user, $taskLimit);
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
            } elseif ($items === []) {
                $emptyReasoning = trim((string) $selection['deterministic_summary']);
                $fallbackReasoning = $emptyReasoning !== '' ? $emptyReasoning : TaskAssistantListingDefaults::reasoningWhenEmpty();
                $prioritizeData = [
                    'items' => [],
                    'limit_used' => 0,
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
                ];
            } else {
                $narrative = $this->hybridNarrative->refinePrioritizeListing(
                    $promptData,
                    $content,
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
                    'focus' => $narrative['focus'],
                    'acknowledgment' => $narrative['acknowledgment'] ?? null,
                    'framing' => (string) ($narrative['framing'] ?? ''),
                    'reasoning' => (string) ($narrative['reasoning'] ?? TaskAssistantListingDefaults::reasoningWhenEmpty()),
                    // Standardized follow-ups: deterministic and safe.
                    'next_options' => $next['next_options'],
                    'next_options_chip_texts' => $next['next_options_chip_texts'],
                ];
            }
        } else {
            $snapshot = $this->snapshotService->buildForUser($thread->user, 100);
            $timezone = (string) ($snapshot['timezone'] ?? config('app.timezone', 'UTC'));
            $now = CarbonImmutable::now($timezone);

            $ranked = $this->prioritizationService->prioritizeFocus($snapshot, $context);
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

            $ambiguous = false;
            $deterministicSummary = $this->buildPrioritizeListingDeterministicSummary(count($items), $ambiguous);
            $filterContextForPrompt = $this->buildPrioritizeListingFilterContextForPrompt($ambiguous, $context);

            if ($isExhaustedNextSlice) {
                $prioritizeData = $this->buildPrioritizeExhaustedData();
            } elseif ($items === []) {
                $emptyReasoning = trim((string) $deterministicSummary);
                $fallbackReasoning = $emptyReasoning !== '' ? $emptyReasoning : TaskAssistantListingDefaults::reasoningWhenEmpty();
                $prioritizeData = [
                    'items' => [],
                    'limit_used' => 0,
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
                ];
            } else {
                $narrative = $this->hybridNarrative->refinePrioritizeListing(
                    $promptData,
                    $content,
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
                    'focus' => $narrative['focus'],
                    'acknowledgment' => $narrative['acknowledgment'] ?? null,
                    'framing' => (string) ($narrative['framing'] ?? ''),
                    'reasoning' => (string) ($narrative['reasoning'] ?? TaskAssistantListingDefaults::reasoningWhenEmpty()),
                    // Standardized follow-ups: deterministic and safe.
                    'next_options' => $next['next_options'],
                    'next_options_chip_texts' => $next['next_options_chip_texts'],
                ];
            }
        }

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
                count($finalListingItems)
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

    private function shouldUsePrioritizeTaskListMode(ExecutionPlan $plan, string $content): bool
    {
        if (preg_match('/\b(prioritize|focus)\b/i', $content) === 1) {
            return false;
        }

        $msg = mb_strtolower(trim($content));

        return (bool) preg_match('/\b(list|show|display|give me|what)\s+(all\s+)?(my\s+)?tasks?\b/i', $msg);
    }

    private function isPrioritizeNextSliceFollowup(TaskAssistantThread $thread, ExecutionPlan $plan, string $content): bool
    {
        $fromRouting = (bool) ($plan->constraints['prioritize_followup'] ?? false);
        if (! $fromRouting) {
            return false;
        }

        if ($this->conversationState->lastListing($thread) === null) {
            return false;
        }

        return preg_match('/\bshow\s+next(\s+\d+)?\b|\bnext\s+\d+\b|\bshow\s+more\b/i', $content) === 1;
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
    private function buildPrioritizeExhaustedData(): array
    {
        return [
            'items' => [],
            'limit_used' => 0,
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

        if (! $hasMoreUnseen) {
            $nextOptions = 'You are caught up on the unseen priorities from this list. If you want, I can schedule tasks next, or refine your priorities with a filter.';

            return [
                'next_options' => TaskAssistantListingDefaults::clampNextField($nextOptions),
                'next_options_chip_texts' => [
                    'Schedule tasks',
                    'Refine list',
                ],
            ];
        }

        $hasNonTask = false;
        foreach ($rows as $row) {
            $type = strtolower(trim((string) ($row['entity_type'] ?? 'task')));
            if ($type !== 'task') {
                $hasNonTask = true;
                break;
            }
        }

        if ($count <= 1) {
            $nextOptions = 'If you want, I can schedule this for later, or show your next 3 priorities.';

            return [
                'next_options' => TaskAssistantListingDefaults::clampNextField($nextOptions),
                'next_options_chip_texts' => [
                    'Schedule this',
                    'Show next 3',
                ],
            ];
        }

        $nextOptions = $hasNonTask
            ? 'If you want, I can schedule time for the task(s) on this list, or show your next 3 priorities.'
            : 'If you want, I can schedule time for these, or show your next 3 priorities.';

        return [
            'next_options' => TaskAssistantListingDefaults::clampNextField($nextOptions),
            'next_options_chip_texts' => [
                'Schedule these',
                'Show next 3',
            ],
        ];
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
            if (($context['time_constraint'] ?? null) !== null) {
                $parts[] = 'time: '.(string) $context['time_constraint'];
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
            if (($context['time_constraint'] ?? null) !== null) {
                $parts[] = 'time: '.(string) $context['time_constraint'];
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
        if (($context['time_constraint'] ?? null) !== null) {
            $parts[] = 'time: '.(string) $context['time_constraint'];
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
            return 'No tasks matched your request.';
        }

        if ($ambiguous) {
            return 'Here are '.$count.' tasks from your list, ordered by urgency and due dates:';
        }

        return 'Found '.$count.' task(s).';
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

    private function runGeneralGuidanceFlow(
        TaskAssistantThread $thread,
        TaskAssistantMessage $assistantMessage,
        string $userMessage,
        ExecutionPlan $plan,
        ?string $forcedClarifyingQuestion = null
    ): void {
        Log::info('task-assistant.flow', [
            'layer' => 'flow',
            'flow' => 'general_guidance',
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessage->id,
        ]);

        if (in_array('intent_off_topic', $plan->reasonCodes, true)) {
            // Strong guardrail to keep Hermes in the task assistant domain even
            // when users ask unrelated questions (relationships, politics, product
            // recommendations, etc.). We still require the general_guidance schema.
            $userMessage .= "\n\nOFF_TOPIC_GUARDRAIL: This request is off-topic for a task assistant. Acknowledge briefly, refuse to help with the unrelated topic, and suggest task-focused next steps (prioritize tasks or schedule time blocks) while following the current general_guidance schema.";
        }

        $guidance = $this->generalGuidanceService->generateGeneralGuidance(
            user: $thread->user,
            userMessage: $userMessage,
            forcedClarifyingQuestion: $forcedClarifyingQuestion,
            forcedMode: in_array('intent_off_topic', $plan->reasonCodes, true) ? 'off_topic' : null,
        );

        $state = $this->conversationState->get($thread);

        // Add a deterministic "intro" the first time we respond with
        // general_guidance in this thread.
        $shouldIntroduce = ! (bool) ($state['general_guidance_intro_done'] ?? false);
        if ($shouldIntroduce) {
            $intro = "Hi, I'm TaskLyst—your task assistant.";
            $currentMessage = trim((string) ($guidance['response'] ?? ''));

            if ($currentMessage !== '' && ! str_starts_with($currentMessage, $intro)) {
                $currentMessage = $intro.' '.$currentMessage;
            }

            // If the model still adds another greeting right after the intro,
            // remove the second greeting sentence to keep things non-repetitive.
            $currentMessage = preg_replace(
                '/^(Hi, I\'m TaskLyst—your task assistant\.)\s+(hi|hello|hey)\b[^.!?]*[.!?]\s*/iu',
                '$1 ',
                (string) $currentMessage
            ) ?: $currentMessage;

            // Keep within general guidance validation bounds.
            if (mb_strlen($currentMessage) > 500) {
                $currentMessage = mb_substr($currentMessage, 0, 500);
            }

            $guidance['response'] = $currentMessage;

            $state['general_guidance_intro_done'] = true;
            $this->conversationState->put($thread, $state);
        }

        // Avoid repeating the exact same redirect question if the user
        // keeps asking different general prompts in the same thread.
        $currentQuestion = (string) ($guidance['clarifying_question'] ?? '');
        $lastQuestion = (string) ($state['last_general_guidance_clarifying_question'] ?? '');
        if (
            ($guidance['guidance_mode'] ?? 'friendly_general') === 'gibberish_unclear'
            && $forcedClarifyingQuestion === null
            && $currentQuestion !== ''
            && $currentQuestion === $lastQuestion
        ) {
            $questionVariants = [
                'I did not catch that clearly yet. Can you rephrase your request in one short sentence?',
                'Could you say that again in one short, clear sentence so I can help?',
                'I want to help, but I did not understand that message. Can you rephrase it clearly?',
                'Can you rewrite your request in one simple sentence so I can guide you better?',
            ];

            $lastVariantIdx = (int) ($state['last_general_guidance_question_variant'] ?? 0);
            $nextVariantIdx = ($lastVariantIdx + 1) % count($questionVariants);
            $guidance['clarifying_question'] = $questionVariants[$nextVariantIdx];

            $state['last_general_guidance_question_variant'] = $nextVariantIdx;
        }

        $state['last_general_guidance_clarifying_question'] = (string) ($guidance['clarifying_question'] ?? '');
        $this->conversationState->put($thread, $state);

        $generationResult = [
            'valid' => true,
            'data' => [
                'guidance_mode' => (string) ($guidance['guidance_mode'] ?? 'friendly_general'),
                'response' => (string) ($guidance['response'] ?? ''),
                'next_step_guidance' => (string) ($guidance['next_step_guidance'] ?? ''),
                'clarifying_question' => $guidance['clarifying_question'] ?? null,
                'redirect_target' => $guidance['redirect_target'] ?? null,
                'suggested_replies' => is_array($guidance['suggested_replies'] ?? null)
                    ? $guidance['suggested_replies']
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

        // Store pending guidance for the next user message.
        $clarifyingQuestion = (string) ($guidance['clarifying_question'] ?? '');
        $guidanceMode = (string) ($guidance['guidance_mode'] ?? 'friendly_general');

        Log::info('task-assistant.general_guidance.telemetry', [
            'layer' => 'flow',
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessage->id,
            'guidance_mode' => $guidanceMode,
            'had_question' => $clarifyingQuestion !== '',
            'had_redirect_target' => trim((string) ($guidance['redirect_target'] ?? '')) !== '',
        ]);
        if ($clarifyingQuestion !== '' && in_array($guidanceMode, ['gibberish_unclear', 'off_topic'], true)) {
            $this->conversationState->rememberPendingGeneralGuidance(
                $thread,
                $userMessage,
                $clarifyingQuestion,
                $plan->reasonCodes
            );
        } else {
            $this->conversationState->clearPendingGeneralGuidance($thread);
        }

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
