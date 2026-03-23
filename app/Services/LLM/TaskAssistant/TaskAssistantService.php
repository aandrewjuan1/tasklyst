<?php

namespace App\Services\LLM\TaskAssistant;

use App\Enums\MessageRole;
use App\Enums\TaskComplexity;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\Browse\TaskAssistantListingSelectionService;
use App\Services\LLM\Prioritization\TaskAssistantTaskChoiceConstraintsExtractor;
use App\Services\LLM\Prioritization\TaskPrioritizationService;
use App\Services\LLM\Scheduling\TaskAssistantStructuredFlowGenerator;
use App\Support\LLM\TaskAssistantListingDefaults;
use Carbon\CarbonImmutable;
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
        private readonly TaskAssistantGeneralGuidanceService $generalGuidanceService,
        private readonly IntentRoutingPolicy $routingPolicy,
        private readonly TaskAssistantHybridNarrativeService $hybridNarrative,
        private readonly TaskAssistantListingSelectionService $listingSelectionService,
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

            // `chat` is intentionally not used anymore. If we ever get an unknown
            // flow value, fall back to general guidance.
            $this->runGeneralGuidanceFlow($thread, $assistantMessage, $content, $plan);
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

        $items = [];
        $prioritizeData = [];

        if ($prioritizeTaskListMode) {
            $taskLimit = max(1, (int) config('task-assistant.listing.snapshot_task_limit', 200));
            $snapshot = $this->snapshotService->buildForUser($thread->user, $taskLimit);
            $selection = $this->listingSelectionService->build($content, $snapshot, $plan->countLimit);
            $items = $selection['items'];

            $promptData = $this->promptData->forUser($thread->user);
            $promptData['snapshot'] = $snapshot;
            $promptData['route_context'] = (string) config('task-assistant.listing_route_context', '');

            if ($items === []) {
                $fallbacks = TaskAssistantHybridNarrativeService::prioritizeListingNarrativeFallbacks();
                $emptyReasoning = trim((string) $selection['deterministic_summary']);
                $prioritizeData = [
                    'items' => [],
                    'limit_used' => 0,
                    'reasoning' => TaskAssistantListingDefaults::clampBrowseReasoning(
                        $emptyReasoning !== ''
                            ? $emptyReasoning
                            : TaskAssistantListingDefaults::reasoningWhenEmpty()
                    ),
                    'suggested_guidance' => TaskAssistantListingDefaults::clampBrowseSuggestedGuidance(
                        $fallbacks['suggested_guidance']
                    ),
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

                $prioritizeData = [
                    'items' => $items,
                    'limit_used' => count($items),
                    'reasoning' => TaskAssistantListingDefaults::clampBrowseReasoning((string) $narrative['reasoning']),
                    'suggested_guidance' => TaskAssistantListingDefaults::clampBrowseSuggestedGuidance((string) $narrative['suggested_guidance']),
                ];
            }
        } else {
            $snapshot = $this->snapshotService->buildForUser($thread->user, 100);
            $timezone = (string) ($snapshot['timezone'] ?? config('app.timezone', 'UTC'));
            $now = CarbonImmutable::now($timezone);

            $ranked = $this->prioritizationService->prioritizeFocus($snapshot, $context);
            $itemsRaw = array_slice($ranked, 0, $plan->countLimit);

            foreach ($itemsRaw as $candidate) {
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
                    $items[] = $this->buildPrioritizeListingTaskRowFromRawTask($raw, $id, $title, $now, $timezone);

                    continue;
                }

                $items[] = [
                    'entity_type' => $type,
                    'entity_id' => $id,
                    'title' => $title,
                ];
            }

            $promptData = $this->promptData->forUser($thread->user);
            $promptData['snapshot'] = $snapshot;
            $promptData['route_context'] = (string) config('task-assistant.listing_route_context', '');

            $ambiguous = false;
            $deterministicSummary = $this->buildPrioritizeListingDeterministicSummary(count($items), $ambiguous);
            $filterContextForPrompt = $this->buildPrioritizeListingFilterContextForPrompt($ambiguous, $context);

            if ($items === []) {
                $fallbacks = TaskAssistantHybridNarrativeService::prioritizeListingNarrativeFallbacks();
                $emptyReasoning = trim((string) $deterministicSummary);
                $prioritizeData = [
                    'items' => [],
                    'limit_used' => 0,
                    'reasoning' => TaskAssistantListingDefaults::clampBrowseReasoning(
                        $emptyReasoning !== ''
                            ? $emptyReasoning
                            : TaskAssistantListingDefaults::reasoningWhenEmpty()
                    ),
                    'suggested_guidance' => TaskAssistantListingDefaults::clampBrowseSuggestedGuidance(
                        $fallbacks['suggested_guidance']
                    ),
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

                $prioritizeData = [
                    'items' => $items,
                    'limit_used' => count($items),
                    'reasoning' => TaskAssistantListingDefaults::clampBrowseReasoning((string) $narrative['reasoning']),
                    'suggested_guidance' => TaskAssistantListingDefaults::clampBrowseSuggestedGuidance((string) $narrative['suggested_guidance']),
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

        if ($items === []) {
            $this->conversationState->clearLastListing($thread);
        } else {
            $this->conversationState->rememberLastListing(
                $thread,
                'prioritize',
                $items,
                $assistantMessage->id,
                count($items)
            );
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
            $userMessage .= "\n\nOFF_TOPIC_GUARDRAIL: This request is off-topic for a task assistant. Acknowledge briefly, refuse to help with the unrelated topic, and redirect toward prioritization vs scheduling. Output must still follow the general_guidance schema (message + exactly one clarifying_question that asks which next action the user wants: prioritize tasks or schedule time blocks).";
        }

        $guidance = $this->generalGuidanceService->generateGeneralGuidance(
            user: $thread->user,
            userMessage: $userMessage,
            forcedClarifyingQuestion: $forcedClarifyingQuestion
        );

        $state = $this->conversationState->get($thread);

        if (in_array('intent_off_topic', $plan->reasonCodes, true)) {
            // Post-process off-topic responses to enforce a short refusal +
            // redirect, keeping the rest of the structured flow intact.
            $variants = [
                "I can't help with that topic. I'm a task assistant. If you tell me what tasks you have, I can help you prioritize your tasks or schedule time blocks for them.",
                "I can't help with that directly. I'm a task assistant focused on your tasks—share what you're working on and I'll help you prioritize your tasks or schedule time blocks.",
            ];

            $idx = ($thread->id % count($variants));
            $guidance['message'] = $variants[$idx];
        }

        // Add a deterministic "intro" the first time we respond with
        // general_guidance in this thread.
        $shouldIntroduce = ! (bool) ($state['general_guidance_intro_done'] ?? false);
        if ($shouldIntroduce) {
            $intro = "Hi, I'm TaskLyst—your task assistant.";
            $currentMessage = trim((string) ($guidance['message'] ?? ''));

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

            $guidance['message'] = $currentMessage;

            $state['general_guidance_intro_done'] = true;
            $this->conversationState->put($thread, $state);
        }

        // Avoid repeating the exact same redirect question if the user
        // keeps asking different general prompts in the same thread.
        $currentQuestion = (string) ($guidance['clarifying_question'] ?? '');
        $lastQuestion = (string) ($state['last_general_guidance_clarifying_question'] ?? '');
        if ($forcedClarifyingQuestion === null && $currentQuestion !== '' && $currentQuestion === $lastQuestion) {
            $questionVariants = [
                'Do you want me to prioritize your tasks or schedule time blocks for them?',
                'Should we prioritize tasks first, or schedule time blocks to work on them?',
                'Which next action fits better: prioritizing your tasks or scheduling time blocks?',
                'Prioritize your tasks or schedule time blocks - what works best?',
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
                'message' => (string) ($guidance['message'] ?? ''),
                'clarifying_question' => (string) ($guidance['clarifying_question'] ?? ''),
                'redirect_target' => (string) ($guidance['redirect_target'] ?? 'either'),
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
        if ($clarifyingQuestion !== '') {
            $this->conversationState->rememberPendingGeneralGuidance(
                $thread,
                $userMessage,
                $clarifyingQuestion,
                $plan->reasonCodes
            );
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
        $flow = match ($decision->flow) {
            'chat' => 'general_guidance',
            'prioritize',
            'schedule',
            'general_guidance' => $decision->flow,
            default => 'general_guidance',
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
}
