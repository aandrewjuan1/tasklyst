<?php

namespace App\Services\LLM\TaskAssistant;

use App\Enums\MessageRole;
use App\Enums\TaskAssistantPrioritizeVariant;
use App\Enums\TaskComplexity;
use App\Enums\TaskStatus;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use App\Services\LLM\Prioritization\AssistantCandidateProvider;
use App\Services\LLM\Prioritization\TaskAssistantTaskChoiceConstraintsExtractor;
use App\Services\LLM\Prioritization\TaskPrioritizationService;
use App\Services\LLM\Scheduling\DeterministicScheduleExplanationService;
use App\Services\LLM\Scheduling\PlacementDigestRebuilder;
use App\Services\LLM\Scheduling\ScheduleDraftMetadataNormalizer;
use App\Services\LLM\Scheduling\ScheduleDraftMutationService;
use App\Services\LLM\Scheduling\ScheduleEditLexicon;
use App\Services\LLM\Scheduling\ScheduleEditTargetResolver;
use App\Services\LLM\Scheduling\ScheduleEditTemporalParser;
use App\Services\LLM\Scheduling\ScheduleFallbackConfirmationService;
use App\Services\LLM\Scheduling\ScheduleFallbackPolicy;
use App\Services\LLM\Scheduling\ScheduleFallbackReasonExplainer;
use App\Services\LLM\Scheduling\ScheduleProposalReferenceService;
use App\Services\LLM\Scheduling\ScheduleRefinementClauseSplitter;
use App\Services\LLM\Scheduling\ScheduleRefinementIntentResolver;
use App\Services\LLM\Scheduling\ScheduleRefinementPlacementRouter;
use App\Services\LLM\Scheduling\ScheduleRefinementStructuredOpExtractor;
use App\Services\LLM\Scheduling\TaskAssistantScheduleDbContextBuilder;
use App\Services\LLM\Scheduling\TaskAssistantScheduleHorizonResolver;
use App\Services\LLM\Scheduling\TaskAssistantStructuredFlowGenerator;
use App\Services\LLM\TaskAssistant\FlowHandlers\TaskAssistantFlowHandlerContext;
use App\Services\LLM\TaskAssistant\FlowHandlers\TaskAssistantFlowHandlerRegistry;
use App\Services\Scheduling\SchoolClassBusyIntervalResolver;
use App\Support\LLM\SchedulableProposalPolicy;
use App\Support\LLM\TaskAssistantFlowNames;
use App\Support\LLM\TaskAssistantPrioritizeOutputDefaults;
use App\Support\LLM\TaskAssistantReasonCodes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Task Assistant orchestration: queued messages are routed once via
 * {@see IntentRoutingPolicy} (LLM + validation), then executed in a flow branch.
 */
final class TaskAssistantService
{
    private const MESSAGE_LIMIT = 50;

    public function __construct(
        private readonly TaskAssistantPromptData $promptData,
        private readonly TaskAssistantScheduleDbContextBuilder $scheduleDbContextBuilder,
        private readonly TaskAssistantStructuredFlowGenerator $structuredFlowGenerator,
        private readonly TaskAssistantFlowExecutionEngine $flowExecutionEngine,
        private readonly TaskAssistantStreamingBroadcaster $streamingBroadcaster,
        private readonly TaskPrioritizationService $prioritizationService,
        private readonly TaskAssistantTaskChoiceConstraintsExtractor $constraintsExtractor,
        private readonly AssistantCandidateProvider $candidateProvider,
        private readonly TaskAssistantConversationStateService $conversationState,
        private readonly TaskAssistantGeneralGuidanceService $generalGuidanceService,
        private readonly TaskAssistantQuickChipResolver $quickChipResolver,
        private readonly IntentRoutingPolicy $routingPolicy,
        private readonly TaskAssistantHybridNarrativeService $hybridNarrative,
        private readonly ScheduleDraftMutationService $scheduleDraftMutationService,
        private readonly ScheduleDraftMetadataNormalizer $scheduleDraftMetadataNormalizer,
        private readonly ScheduleRefinementIntentResolver $scheduleRefinementIntentResolver,
        private readonly ScheduleEditTargetResolver $scheduleEditTargetResolver,
        private readonly ScheduleEditTemporalParser $scheduleEditTemporalParser,
        private readonly ScheduleEditLexicon $scheduleEditLexicon,
        private readonly ScheduleRefinementPlacementRouter $scheduleRefinementPlacementRouter,
        private readonly PlacementDigestRebuilder $placementDigestRebuilder,
        private readonly ScheduleRefinementClauseSplitter $scheduleRefinementClauseSplitter,
        private readonly ScheduleRefinementStructuredOpExtractor $scheduleRefinementStructuredOpExtractor,
        private readonly TaskAssistantScheduleHorizonResolver $scheduleHorizonResolver,
        private readonly TaskAssistantListingFollowupService $listingFollowupService,
        private readonly SchoolClassBusyIntervalResolver $schoolClassBusyIntervalResolver,
        private readonly ScheduleProposalReferenceService $scheduleProposalReferenceService,
        private readonly ScheduleFallbackConfirmationService $scheduleFallbackConfirmationService,
        private readonly ScheduleFallbackReasonExplainer $scheduleFallbackReasonExplainer,
        private readonly DeterministicScheduleExplanationService $deterministicScheduleExplanationService,
        private readonly TaskAssistantFlowHandlerRegistry $flowHandlerRegistry,
        private readonly TaskAssistantProcessingGuard $processingGuard,
        private readonly ScheduleFallbackPolicy $scheduleFallbackPolicy,
    ) {}

    public function processQueuedMessage(TaskAssistantThread $thread, int $userMessageId, int $assistantMessageId): void
    {
        // Ensure we have the latest persisted thread metadata/state. In production the
        // queued job loads fresh models; in-process calls (tests) can reuse instances.
        $thread->refresh();

        $runId = (string) Str::uuid();
        app()->instance('task_assistant.run_id', $runId);
        app()->instance('task_assistant.run_started_at_ms', (int) round(microtime(true) * 1000));

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

                    $initialForcedPlan = new ExecutionPlan(
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
                    );
                    $forcedPlan = $this->maybeRemapScheduleToPrioritize($thread, $initialForcedPlan, $content);
                    $this->persistRoutingTrace($assistantMessage, $initialForcedPlan, $forcedPlan);

                    $this->logRoutingDecision($thread, $assistantMessage, $forcedPlan);

                    if ($forcedPlan->clarificationNeeded) {
                        $this->runNamedTaskClarificationFlow($thread, $assistantMessage, $content, $forcedPlan);

                        return;
                    }

                    $candidateSnapshot = $this->candidateProvider->candidatesForUser(
                        $thread->user,
                        taskLimit: $this->snapshotTaskLimit(),
                    );
                    if ($this->isWorkspaceCandidateSnapshotEmpty($candidateSnapshot)) {
                        $this->logWorkspaceEmptyShortcircuit($thread, $assistantMessageId, $forcedPlan->flow);
                        $this->runEmptyWorkspaceFlow($thread, $assistantMessage, $content, $forcedPlan);

                        return;
                    }

                    if ($forcedPlan->flow === 'prioritize') {
                        $this->runPrioritizeFlow($thread, $assistantMessage, $content, $forcedPlan);

                        return;
                    }

                    if ($forcedPlan->flow === 'prioritize_schedule') {
                        $this->runPrioritizeScheduleFlow($thread, $userMessage, $assistantMessage, $content, $forcedPlan);

                        return;
                    }

                    $this->runScheduleFlow($thread, $userMessage, $assistantMessage, $content, $forcedPlan);

                    return;
                }
            }

            $pendingScheduleFallback = $this->conversationState->pendingScheduleFallback($thread);
            if ($pendingScheduleFallback !== null) {
                $pendingActionId = $this->extractClientActionId($userMessage);
                $handled = $this->handlePendingScheduleFallbackDecision(
                    thread: $thread,
                    userMessage: $userMessage,
                    assistantMessage: $assistantMessage,
                    userMessageContent: $content,
                    pendingState: $pendingScheduleFallback,
                    pendingActionId: $pendingActionId,
                );
                if ($handled) {
                    return;
                }
            }

            $pendingNamedTaskClarification = $this->conversationState->pendingNamedTaskClarification($thread);
            if ($pendingNamedTaskClarification !== null) {
                $handled = $this->handlePendingNamedTaskClarification(
                    thread: $thread,
                    userMessage: $userMessage,
                    assistantMessage: $assistantMessage,
                    userMessageContent: $content,
                    pendingState: $pendingNamedTaskClarification,
                );
                if ($handled) {
                    return;
                }
            }

            if ($this->handleDeterministicChipClientAction(
                thread: $thread,
                userMessage: $userMessage,
                assistantMessage: $assistantMessage,
                assistantMessageId: $assistantMessageId,
                content: $content,
            )) {
                return;
            }

            $initialPlan = $this->buildExecutionPlan($thread, $content);
            $plan = $this->maybeRemapScheduleToPrioritize($thread, $initialPlan, $content);
            $plan = $this->maybeRewritePlanForScheduleRefinement($thread, $plan, $assistantMessage->id, $content);
            $this->persistRoutingTrace($assistantMessage, $initialPlan, $plan);
            $this->logRoutingDecision($thread, $assistantMessage, $plan);

            if ($plan->clarificationNeeded) {
                $this->runNamedTaskClarificationFlow($thread, $assistantMessage, $content, $plan);

                return;
            }

            if (in_array($plan->flow, [TaskAssistantFlowNames::PRIORITIZE, TaskAssistantFlowNames::SCHEDULE, TaskAssistantFlowNames::PRIORITIZE_SCHEDULE, TaskAssistantFlowNames::LISTING_FOLLOWUP], true)) {
                $candidateSnapshot = $this->candidateProvider->candidatesForUser(
                    $thread->user,
                    taskLimit: $this->snapshotTaskLimit(),
                );
                if ($this->isWorkspaceCandidateSnapshotEmpty($candidateSnapshot)) {
                    $this->logWorkspaceEmptyShortcircuit($thread, $assistantMessageId, $plan->flow);
                    $this->runEmptyWorkspaceFlow($thread, $assistantMessage, $content, $plan);

                    return;
                }
            }

            if ($plan->flow === TaskAssistantFlowNames::GENERAL_GUIDANCE) {
                $this->runGeneralGuidanceFlow($thread, $assistantMessage, $content, $plan);

                return;
            }

            if ($plan->flow === TaskAssistantFlowNames::LISTING_FOLLOWUP) {
                $this->runListingFollowupFlow($thread, $assistantMessage, $content, $plan);

                return;
            }

            if (in_array($plan->flow, [
                TaskAssistantFlowNames::PRIORITIZE,
                TaskAssistantFlowNames::PRIORITIZE_SCHEDULE,
                TaskAssistantFlowNames::SCHEDULE_REFINEMENT,
                TaskAssistantFlowNames::SCHEDULE,
            ], true)) {
                $this->dispatchFlowHandler(new TaskAssistantFlowHandlerContext(
                    thread: $thread,
                    userMessage: $userMessage,
                    assistantMessage: $assistantMessage,
                    content: $content,
                    plan: $plan,
                ));

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
            app()->forgetInstance('task_assistant.run_started_at_ms');
        }
    }

    public function executePrioritizeFlow(TaskAssistantFlowHandlerContext $context): void
    {
        $this->runPrioritizeFlow($context->thread, $context->assistantMessage, $context->content, $context->plan);
    }

    public function executePrioritizeScheduleFlow(TaskAssistantFlowHandlerContext $context): void
    {
        $this->runPrioritizeScheduleFlow(
            $context->thread,
            $context->userMessage,
            $context->assistantMessage,
            $context->content,
            $context->plan
        );
    }

    private function snapshotTaskLimit(): int
    {
        return max(1, (int) config('task-assistant.listing.snapshot_task_limit', 200));
    }

    public function executeScheduleRefinementFlow(TaskAssistantFlowHandlerContext $context): void
    {
        $this->runScheduleRefinementFlow(
            $context->thread,
            $context->userMessage,
            $context->assistantMessage,
            $context->content,
            $context->plan
        );
    }

    public function executeScheduleFlow(TaskAssistantFlowHandlerContext $context): void
    {
        $this->runScheduleFlow(
            $context->thread,
            $context->userMessage,
            $context->assistantMessage,
            $context->content,
            $context->plan
        );
    }

    private function dispatchFlowHandler(TaskAssistantFlowHandlerContext $context): void
    {
        $this->flowHandlerRegistry
            ->resolve($context->plan->flow)
            ->handle($context);
    }

    /**
     * @param  array<string, mixed>  $snapshot  Shape from {@see AssistantCandidateProvider::candidatesForUser}
     */
    private function isWorkspaceCandidateSnapshotEmpty(array $snapshot): bool
    {
        $tasks = is_array($snapshot['tasks'] ?? null) ? $snapshot['tasks'] : [];
        $events = is_array($snapshot['events'] ?? null) ? $snapshot['events'] : [];
        $projects = is_array($snapshot['projects'] ?? null) ? $snapshot['projects'] : [];

        return $tasks === [] && $events === [] && $projects === [];
    }

    private function logWorkspaceEmptyShortcircuit(TaskAssistantThread $thread, int $assistantMessageId, string $intendedFlow): void
    {
        Log::info('task-assistant.orchestration', [
            'layer' => 'orchestration',
            'stage' => 'workspace_empty_shortcircuit',
            'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessageId,
            'intended_flow' => $intendedFlow,
        ]);
    }

    /**
     * When schedule was remapped to prioritize (no listing), preserve the user's scheduling intent for empty-workspace telemetry.
     */
    private function workspaceEmptyOriginalIntentFlow(ExecutionPlan $plan): string
    {
        if (in_array(TaskAssistantReasonCodes::SCHEDULE_REROUTED_NO_LISTING_CONTEXT, $plan->reasonCodes, true)) {
            return 'schedule';
        }

        return $plan->flow;
    }

    /**
     * Deterministic prioritize-shaped reply when there is nothing to rank or schedule yet.
     */
    private function runEmptyWorkspaceFlow(
        TaskAssistantThread $thread,
        TaskAssistantMessage $assistantMessage,
        string $content,
        ExecutionPlan $plan,
    ): void {
        $thread->refresh();

        $cfg = config('task-assistant.listing.empty_workspace', []);
        $cfg = is_array($cfg) ? $cfg : [];

        $focusMain = trim((string) ($cfg['focus_main_task'] ?? ''));
        if ($focusMain === '') {
            $focusMain = 'Add your first task';
        }

        $framingRaw = trim((string) ($cfg['framing'] ?? ''));
        if ($framingRaw === '') {
            $framingRaw = 'You do not have any tasks, events, or projects here yet. Once you add something, I can help you choose what to tackle first or block time for it.';
        }

        $reasoningRaw = trim((string) ($cfg['reasoning'] ?? ''));
        if ($reasoningRaw === '') {
            $reasoningRaw = 'Getting one concrete item on your list is enough to start—try the thing that is due soonest or on your mind the most, then come back and ask for a ranked order or a schedule.';
        }

        $nextRaw = trim((string) ($cfg['next_options'] ?? ''));
        if ($nextRaw === '') {
            $nextRaw = 'Add something from your workspace, then tell me what to do first or when you want to work on it.';
        }

        $reasonCodes = array_values(array_unique(array_merge(
            $plan->reasonCodes,
            ['workspace_empty_shortcircuit']
        )));

        $prioritizeData = [
            'items' => [],
            'limit_used' => 0,
            'doing_progress_coach' => null,
            'focus' => [
                'main_task' => $focusMain,
                'secondary_tasks' => [],
            ],
            'acknowledgment' => null,
            'framing' => TaskAssistantPrioritizeOutputDefaults::clampFraming($framingRaw),
            'reasoning' => TaskAssistantPrioritizeOutputDefaults::clampPrioritizeReasoning($reasoningRaw),
            'next_options' => TaskAssistantPrioritizeOutputDefaults::clampNextField($nextRaw),
            'next_options_chip_texts' => [],
            'filter_interpretation' => null,
            'assumptions' => null,
            'workspace_empty' => true,
            'workspace_empty_intended_flow' => $this->workspaceEmptyOriginalIntentFlow($plan),
            'workspace_empty_reason_codes' => $reasonCodes,
        ];

        $this->attachPrioritizeOrchestrationMetadata($prioritizeData);

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
            assistantFallbackContent: 'I could not build a task list yet. Try again with a bit more detail.',
        );

        $this->conversationState->clearLastListing($thread);

        $this->streamFlowEnvelope(
            thread: $thread,
            assistantMessage: $assistantMessage,
            flow: 'prioritize',
            execution: $execution
        );
    }

    private function runPrioritizeFlow(TaskAssistantThread $thread, TaskAssistantMessage $assistantMessage, string $content, ExecutionPlan $plan): void
    {
        $thread->refresh();

        Log::info('task-assistant.flow', [
            'layer' => 'flow',
            'flow' => 'prioritize',
            'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessage->id,
            'count_limit' => $plan->countLimit,
            'prioritize_variant' => TaskAssistantPrioritizeVariant::Rank->value,
            ...$this->buildInferenceTelemetry($plan),
        ]);

        $context = $this->constraintsExtractor->extract($content);

        $snapshot = $this->candidateProvider->candidatesForUser(
            $thread->user,
            taskLimit: $this->snapshotTaskLimit(),
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

        $doingMeta = $this->collectDoingTasksFromSnapshot($snapshot);
        $doingTitlesForPayload = array_slice($doingMeta['titles'], 0, 12);

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
                if (($raw['status'] ?? '') === TaskStatus::Doing->value) {
                    continue;
                }
                $allItems[] = $this->buildPrioritizeListingTaskRowFromRawTask(
                    $raw,
                    $id,
                    $title,
                    $now,
                    $timezone,
                    is_array($candidate['explainability'] ?? null) ? $candidate['explainability'] : []
                );

                continue;
            }

            $allItems[] = [
                'entity_type' => $type,
                'entity_id' => $id,
                'title' => $title,
                'rank_explainability' => is_array($candidate['explainability'] ?? null) ? $candidate['explainability'] : null,
                'rank_reason' => $this->studentRankReasonFromCandidate($candidate),
            ];
        }

        $limit = max(1, min($plan->countLimit, 10));
        $items = array_values(array_slice($allItems, 0, $limit));
        $rankingMethodSummary = $this->buildPrioritizeRankingMethodSummary();
        $orderingRationale = $this->buildPrioritizeOrderingRationale($items);
        $hasMoreUnseen = count($allItems) > count($items);
        $explicitRequestedCount = $this->extractExplicitRequestedCount($content);
        $countMismatchContext = [
            'requested_count' => $limit,
            'actual_count' => count($items),
            'has_count_mismatch' => count($items) > 0 && count($items) < $limit,
            'explicit_requested_count' => $explicitRequestedCount,
        ];

        $promptData = $this->promptData->forUser($thread->user);
        $promptData['snapshot'] = $snapshot;
        $promptData['route_context'] = (string) config('task-assistant.listing_route_context', '');
        $promptData['prioritize_variant'] = TaskAssistantPrioritizeVariant::Rank->value;
        if ($doingMeta['count'] > 0) {
            $promptData['doing_context'] = [
                'has_doing_tasks' => true,
                'doing_titles' => $doingTitlesForPayload,
                'doing_count' => $doingMeta['count'],
            ];
        }

        $ambiguous = false;
        $deterministicSummary = $this->buildPrioritizeListingDeterministicSummary(count($items), $ambiguous);
        $filterContextForPrompt = $this->buildPrioritizeListingFilterContextForPrompt($ambiguous, $context);

        if ($items === [] && $doingMeta['count'] > 0) {
            $narrative = $this->hybridNarrative->refinePrioritizeListing(
                $promptData,
                $content,
                [],
                $deterministicSummary,
                $filterContextForPrompt,
                $ambiguous,
                $thread->id,
                $thread->user_id,
                $countMismatchContext
            );

            $next = $this->buildDeterministicPrioritizeNextOptions(
                $narrative['items'] ?? [],
                $hasMoreUnseen
            );

            $prioritizeData = [
                'items' => $narrative['items'],
                'limit_used' => 0,
                'doing_progress_coach' => $narrative['doing_progress_coach']
                    ?? TaskAssistantPrioritizeOutputDefaults::buildDoingProgressCoach($doingTitlesForPayload, $doingMeta['count']),
                'focus' => $narrative['focus'],
                'acknowledgment' => $narrative['acknowledgment'] ?? null,
                'framing' => $narrative['framing'] ?? null,
                'reasoning' => (string) ($narrative['reasoning'] ?? TaskAssistantPrioritizeOutputDefaults::reasoningWhenEmpty()),
                'next_options' => $next['next_options'],
                'next_options_chip_texts' => $next['next_options_chip_texts'],
                'filter_interpretation' => $narrative['filter_interpretation'] ?? null,
                'assumptions' => $narrative['assumptions'] ?? null,
                'count_mismatch_explanation' => null,
                'ranking_method_summary' => $rankingMethodSummary,
                'ordering_rationale' => $orderingRationale,
            ];
        } elseif ($items === []) {
            $framingText = trim((string) $deterministicSummary);
            if ($framingText === '') {
                $framingText = (string) __('No matches yet—try widening filters or adding a task that fits what you asked.');
            }
            $reasoningText = (string) __('When something matches, it will show up here in urgency order—you can adjust filters, add a task, or ask again in different words.');
            $prioritizeData = [
                'items' => [],
                'limit_used' => 0,
                'doing_progress_coach' => null,
                'focus' => [
                    'main_task' => 'No matching items found',
                    'secondary_tasks' => [],
                ],
                'acknowledgment' => null,
                'framing' => TaskAssistantPrioritizeOutputDefaults::clampFraming($framingText),
                'reasoning' => TaskAssistantPrioritizeOutputDefaults::clampPrioritizeReasoning($reasoningText),
                'next_options' => TaskAssistantPrioritizeOutputDefaults::clampNextField(
                    (string) __('After you add or adjust tasks, I can help sort what to do first or plan time for them.'),
                ),
                'next_options_chip_texts' => [],
                'filter_interpretation' => null,
                'assumptions' => null,
                'count_mismatch_explanation' => null,
                'ranking_method_summary' => $rankingMethodSummary,
                'ordering_rationale' => $orderingRationale,
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
                $countMismatchContext
            );

            $narrativeItems = is_array($narrative['items'] ?? null) ? $narrative['items'] : [];
            $countMismatchExplanation = $this->resolvePrioritizeCountMismatchExplanation(
                $countMismatchContext,
                count($narrativeItems),
                $narrative['count_mismatch_explanation'] ?? null
            );

            $next = $this->buildDeterministicPrioritizeNextOptions(
                $narrative['items'] ?? [],
                $hasMoreUnseen
            );

            $prioritizeData = [
                'items' => $narrative['items'],
                'limit_used' => count($narrative['items']),
                'doing_progress_coach' => $doingMeta['count'] > 0
                    ? ($narrative['doing_progress_coach']
                        ?? TaskAssistantPrioritizeOutputDefaults::buildDoingProgressCoach($doingTitlesForPayload, $doingMeta['count']))
                    : null,
                'focus' => $narrative['focus'],
                'acknowledgment' => $narrative['acknowledgment'] ?? null,
                'framing' => $narrative['framing'] ?? null,
                'reasoning' => (string) ($narrative['reasoning'] ?? TaskAssistantPrioritizeOutputDefaults::reasoningWhenEmpty()),
                'next_options' => $next['next_options'],
                'next_options_chip_texts' => $next['next_options_chip_texts'],
                'filter_interpretation' => $narrative['filter_interpretation'] ?? null,
                'assumptions' => $narrative['assumptions'] ?? null,
                'count_mismatch_explanation' => $countMismatchExplanation,
                'ranking_method_summary' => $rankingMethodSummary,
                'ordering_rationale' => $orderingRationale,
            ];
        }

        $this->attachPrioritizeOrchestrationMetadata($prioritizeData);

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
            $this->conversationState->clearLastListing($thread);
        } else {
            $this->conversationState->rememberLastListing(
                $thread,
                'prioritize',
                $finalListingItems,
                $assistantMessage->id,
                count($finalListingItems),
            );
        }

        $this->streamFlowEnvelope(
            thread: $thread,
            assistantMessage: $assistantMessage,
            flow: 'prioritize',
            execution: $execution
        );
    }

    /**
     * @param  array<string, mixed>  $prioritizeData
     */
    private function attachPrioritizeOrchestrationMetadata(array &$prioritizeData): void
    {
        $prioritizeData['prioritize_variant'] = TaskAssistantPrioritizeVariant::Rank->value;
    }

    /**
     * @param  array{requested_count:int, actual_count:int, has_count_mismatch:bool, explicit_requested_count:int|null}  $countMismatchContext
     */
    private function resolvePrioritizeCountMismatchExplanation(array $countMismatchContext, int $actualCount, mixed $narrativeExplanation): ?string
    {
        $requestedCount = max(1, (int) ($countMismatchContext['requested_count'] ?? 1));
        $explicitRequestedCount = $countMismatchContext['explicit_requested_count'] ?? null;
        $explicitRequestedCount = is_int($explicitRequestedCount) && $explicitRequestedCount > 0 ? $explicitRequestedCount : null;
        if ($actualCount <= 0 || $actualCount >= $requestedCount) {
            return null;
        }

        if (is_string($narrativeExplanation)) {
            $normalized = trim($narrativeExplanation);
            $hasInvalidCountReference = $explicitRequestedCount === null
                && preg_match('/\b(you asked for|asked for\s+\d+)/i', $normalized) === 1;
            if ($normalized !== '' && ! $this->hasInvalidMismatchExplanationClaims($normalized) && ! $hasInvalidCountReference) {
                $safe = $this->truncateAtSentenceBoundary($normalized, 280);
                if ($safe !== '') {
                    return $safe;
                }
            }
        }

        $itemWord = $actualCount === 1 ? 'item' : 'items';
        if ($explicitRequestedCount !== null) {
            return TaskAssistantPrioritizeOutputDefaults::clampNextField(
                (string) __('You asked for :requested, and I found :actual strong :itemWord for this focus. I’d rather keep you on the clearest next move than pad the list with lower-fit options.', [
                    'requested' => $explicitRequestedCount,
                    'actual' => $actualCount,
                    'itemWord' => $itemWord,
                ])
            );
        }

        return TaskAssistantPrioritizeOutputDefaults::clampNextField(
            (string) __('I found :actual strong :itemWord for this focus right now, so I’m keeping the list tight to what looks most actionable first.', [
                'actual' => $actualCount,
                'itemWord' => $itemWord,
            ])
        );
    }

    private function hasInvalidMismatchExplanationClaims(string $text): bool
    {
        return preg_match('/\b(already started|already working on|in progress|what you(?:\'|’)ve started)\b/i', $text) === 1;
    }

    private function truncateAtSentenceBoundary(string $text, int $maxChars): string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return '';
        }

        if (mb_strlen($trimmed) <= $maxChars) {
            return $trimmed;
        }

        $slice = trim(mb_substr($trimmed, 0, $maxChars));
        if ($slice === '') {
            return '';
        }

        if (preg_match('/^(.+[.!?])(?:\s|$)/u', $slice, $matches) === 1) {
            return trim((string) ($matches[1] ?? $slice));
        }

        $lastSpace = mb_strrpos($slice, ' ');
        if ($lastSpace !== false && $lastSpace > 40) {
            return rtrim(mb_substr($slice, 0, $lastSpace)).'…';
        }

        return rtrim($slice).'…';
    }

    private function extractExplicitRequestedCount(string $content): ?int
    {
        $normalized = mb_strtolower($content);
        if (preg_match('/\btop\s+(\d{1,2})\b/u', $normalized, $matches) === 1) {
            return max(1, min((int) ($matches[1] ?? 1), 10));
        }
        if (preg_match('/\b(\d{1,2})\s+(tasks?|items?|priorities)\b/u', $normalized, $matches) === 1) {
            return max(1, min((int) ($matches[1] ?? 1), 10));
        }

        return null;
    }

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

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function countTodoTasksFromSnapshot(array $snapshot): int
    {
        $tasks = is_array($snapshot['tasks'] ?? null) ? $snapshot['tasks'] : [];
        $count = 0;
        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            if (($task['status'] ?? null) === TaskStatus::ToDo->value) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<string>  $doingTitles
     * @return array{valid: bool, data: array<string, mixed>, errors: list<string>}
     */
    private function buildDoingOnlyScheduleGenerationResult(array $doingTitles, ?string $timeWindowHint, int $countLimit): array
    {
        $firstTitle = trim((string) ($doingTitles[0] ?? 'your current task'));
        $focusLine = $firstTitle !== '' ? $firstTitle : 'your current task';
        $windowLine = $timeWindowHint !== null && $timeWindowHint !== ''
            ? " for {$timeWindowHint}"
            : '';
        $framing = "I will not schedule tasks already marked as in progress. Right now, your focus should stay on {$focusLine}.";
        $reasoning = "You asked to schedule top {$countLimit}{$windowLine}, but only in-progress work is available. Finish the current task first, then ask me to schedule the next to-do task.";
        $confirmation = 'Once you move a task to To Do, ask me again and I will schedule it.';

        $data = [
            'schema_version' => ScheduleDraftMetadataNormalizer::SCHEMA_VERSION,
            'proposals' => [],
            'blocks' => [],
            'items' => [],
            'schedule_variant' => 'daily',
            'framing' => $framing,
            'reasoning' => $reasoning,
            'confirmation' => $confirmation,
            'schedule_empty_placement' => true,
            'placement_digest' => [],
            'window_selection_explanation' => '',
            'ordering_rationale' => [],
            'blocking_reasons' => [],
            'fallback_choice_explanation' => null,
            'window_selection_struct' => [],
            'ordering_rationale_struct' => [],
            'blocking_reasons_struct' => [],
            'confirmation_required' => false,
            'awaiting_user_decision' => false,
            'confirmation_context' => null,
            'fallback_preview' => null,
        ];
        $normalized = $this->scheduleDraftMetadataNormalizer->normalizeAndValidate([
            'schedule' => $data,
            'structured' => ['data' => $data],
        ]);
        $canonicalData = is_array($normalized['canonical_data'] ?? null) ? $normalized['canonical_data'] : $data;

        return [
            'valid' => true,
            'data' => $canonicalData,
            'errors' => [],
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

        if ($count <= 1) {
            return [
                'next_options' => TaskAssistantPrioritizeOutputDefaults::clampNextField(
                    'If you want, I can place this top task later today, tomorrow, or later this week.'
                ),
                'next_options_chip_texts' => [
                    TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule that task for later today'),
                    TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule that task for tomorrow'),
                ],
            ];
        }

        if (! $hasMoreUnseen) {
            $nextOptions = 'This covers the key items for your request. If you want, I can place them later today, tomorrow, or later this week.';

            return [
                'next_options' => TaskAssistantPrioritizeOutputDefaults::clampNextField($nextOptions),
                'next_options_chip_texts' => [
                    TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule those tasks for later today'),
                    TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule those tasks for tomorrow'),
                    TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule only the top task for later'),
                ],
            ];
        }

        $nextOptions = 'If you want, I can place these ranked tasks later today, tomorrow, or later this week.';

        return [
            'next_options' => TaskAssistantPrioritizeOutputDefaults::clampNextField($nextOptions),
            'next_options_chip_texts' => [
                TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule those tasks for later today'),
                TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule those tasks for tomorrow'),
                TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule only the top task for later'),
            ],
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
    private function buildPrioritizeListingTaskRowFromRawTask(
        array $rawTask,
        int $id,
        string $title,
        CarbonImmutable $now,
        string $timezone,
        array $explainability = []
    ): array {
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
                // UX: be specific so the narrative doesn't fall back to vague "due later".
                'due_later' => 'due on '.$dueOn,
                default => 'scheduled',
            };
        }

        $priority = strtolower(trim((string) ($rawTask['priority'] ?? 'medium')));

        $complexityRaw = $rawTask['complexity'] ?? null;
        $complexityLabel = TaskAssistantPrioritizeOutputDefaults::complexityNotSetLabel();
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
            'rank_explainability' => $explainability === [] ? null : $explainability,
            'rank_reason' => $this->studentRankReasonForTaskRow($priority, $duePhrase, $complexityLabel),
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function studentRankReasonFromCandidate(array $candidate): string
    {
        $explainability = is_array($candidate['explainability'] ?? null) ? $candidate['explainability'] : [];
        $reasonCode = strtolower(trim((string) ($explainability['reason_code_primary'] ?? '')));
        if ($reasonCode !== '') {
            return match ($reasonCode) {
                'overdue_task' => 'This needs immediate attention because it is overdue.',
                'due_today_task' => 'This is due today, so handling it now protects your schedule.',
                'due_tomorrow_task' => 'This is due tomorrow and should be prepared early.',
                'high_priority_task' => 'This has high priority and should be tackled early.',
                'event_in_progress' => 'This event is already in progress and is time-sensitive.',
                'event_today' => 'This event happens today and should stay on your immediate plan.',
                'event_tomorrow' => 'This event is tomorrow, so preparing now reduces pressure.',
                'project_overdue' => 'This project is overdue and needs recovery attention.',
                'project_due_today' => 'This project deadline is today and should be prioritized now.',
                'project_due_soon' => 'This project is due soon and should keep momentum.',
                default => 'This is one of your strongest next actions right now.',
            };
        }

        $reasoning = trim((string) ($candidate['reasoning'] ?? ''));
        if ($reasoning !== '') {
            return $reasoning;
        }

        $type = strtolower(trim((string) ($candidate['type'] ?? 'task')));

        return match ($type) {
            'event' => 'This is time-sensitive and should be handled in its current time window.',
            'project' => 'This project has meaningful momentum value and should stay on your radar.',
            default => 'This is one of your strongest next actions right now.',
        };
    }

    private function studentRankReasonForTaskRow(string $priority, string $duePhrase, string $complexityLabel): string
    {
        $priorityLabel = $priority === '' ? 'medium' : $priority;
        $dueDetail = $duePhrase === '' ? 'no due date' : $duePhrase;
        $effortPhrase = match (strtolower(trim($complexityLabel))) {
            'complex' => 'higher effort',
            'moderate' => 'manageable effort',
            'simple' => 'quick effort',
            default => 'unknown effort',
        };

        return "This task stands out because it's {$priorityLabel} priority, {$dueDetail}, and {$effortPhrase}.";
    }

    private function buildPrioritizeRankingMethodSummary(): string
    {
        return TaskAssistantPrioritizeOutputDefaults::defaultRankingMethodSummary();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function buildPrioritizeOrderingRationale(array $items): array
    {
        $lines = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $rank = $index + 1;
            $rankReason = trim((string) ($item['rank_reason'] ?? ''));
            if ($rankReason === '') {
                $rankReason = 'This is one of your clearest next moves right now.';
            }
            $lines[] = "#{$rank} {$title}: {$rankReason}";
        }

        return array_slice($lines, 0, 10);
    }

    private function maybeRewritePlanForScheduleRefinement(
        TaskAssistantThread $thread,
        ExecutionPlan $plan,
        int $currentAssistantMessageId,
        string $content,
    ): ExecutionPlan {
        if ($plan->flow === 'listing_followup') {
            return $plan;
        }

        $isEditPrompt = $this->isLikelyScheduleRefinementEditPrompt($content);
        if (! $isEditPrompt) {
            return $plan;
        }

        if ($plan->flow === 'schedule' && $plan->targetEntities !== []) {
            return $plan;
        }

        $latestDraftSource = $this->findLatestScheduleDraftSourceMessage($thread, $currentAssistantMessageId);
        if ($latestDraftSource === null) {
            return $plan;
        }

        $latestDraftRefinable = $this->assistantMessageHasPendingSchedulableProposals($latestDraftSource);
        Log::info('task-assistant.schedule_refinement.binding', [
            'layer' => 'schedule_refinement',
            'thread_id' => $thread->id,
            'assistant_message_id' => $currentAssistantMessageId,
            'latest_draft_message_id' => $latestDraftSource->id,
            'latest_draft_refinable' => $latestDraftRefinable,
            'fallback_to_fresh_schedule' => ! $latestDraftRefinable,
        ]);
        if (! $latestDraftRefinable) {
            return $this->buildFreshSchedulePlanFromRefinementPlan($plan);
        }

        $reasonCodes = array_values(array_unique(array_merge(
            $plan->reasonCodes,
            [TaskAssistantReasonCodes::SCHEDULE_REFINEMENT_TURN]
        )));

        return new ExecutionPlan(
            flow: 'schedule_refinement',
            confidence: $plan->confidence,
            clarificationNeeded: $plan->clarificationNeeded,
            clarificationQuestion: $plan->clarificationQuestion,
            reasonCodes: $reasonCodes,
            constraints: array_merge($plan->constraints, [
                'schedule_refinement_draft_message_id' => $latestDraftSource->id,
            ]),
            targetEntities: $plan->targetEntities,
            timeWindowHint: $plan->timeWindowHint,
            countLimit: $plan->countLimit,
            generationProfile: 'schedule',
        );
    }

    private function buildFreshSchedulePlanFromRefinementPlan(ExecutionPlan $plan): ExecutionPlan
    {
        $reasonCodes = array_values(array_unique(array_merge(
            $plan->reasonCodes,
            ['schedule_refinement_latest_not_refinable_fallback_schedule']
        )));
        $constraints = $plan->constraints;
        unset($constraints['schedule_refinement_draft_message_id']);

        return new ExecutionPlan(
            flow: 'schedule',
            confidence: $plan->confidence,
            clarificationNeeded: $plan->clarificationNeeded,
            clarificationQuestion: $plan->clarificationQuestion,
            reasonCodes: $reasonCodes,
            constraints: $constraints,
            targetEntities: $plan->targetEntities,
            timeWindowHint: $plan->timeWindowHint,
            countLimit: $plan->countLimit,
            generationProfile: 'schedule',
        );
    }

    private function isLikelyScheduleRefinementEditPrompt(string $content): bool
    {
        $normalized = mb_strtolower(trim($content));
        if ($normalized === '') {
            return false;
        }

        // Preserve explicit fresh-prioritize asks even when a draft schedule exists.
        $looksLikeFreshPrioritize = preg_match(
            '/\b(top|priorit(?:y|ize)|what should i do|what are my top|rank|list)\b/u',
            $normalized
        ) === 1;
        if ($looksLikeFreshPrioritize) {
            return false;
        }

        $hasEditVerb = preg_match('/\b(move|set|change|edit|shift|push|swap|reorder|put|make|reschedule|adjust|bring|bump|drag|slide|delay|advance|pull|drop)\b/u', $normalized) === 1;
        $hasScheduleCue = preg_match(
            '/\b(first|second|third|last|\d+(?:st|nd|rd|th)|item|task|one|it|this|that|same one|before|after|later|earlier|morning|afternoon|evening|night|tonight|tomorrow|today|tmrw|tomorow|next week|next|at\s+\d{1,2}|am|pm|minute|minutes|duration|shorter|longer)\b/u',
            $normalized
        ) === 1;

        $hasStandaloneReorderShape = preg_match(
            '/\b(move|put|swap)\b[^.]*\b(first|second|third|last|item\s*#?\d+)\b[^.]*\b(to|before|after)\b[^.]*\b(first|second|third|last|item\s*#?\d+)\b/u',
            $normalized
        ) === 1;

        $implicitEditPhrase = preg_match(
            '/\b(first|second|third|last|\d+(?:st|nd|rd|th)|#\d+|item\s*#?\d+|task\s*#?\d+)\b.{0,40}\b(instead|please|at|for|to|on)\b.{0,60}\b(morning|afternoon|evening|night|today|tomorrow|tmrw|\d{1,2}(:\d{2})?\s*(am|pm)?)\b/u',
            $normalized
        ) === 1;

        $hasDoIndexedSchedulingPhrase = preg_match(
            '/\bdo\b.{0,16}\b(the\s+)?(first|second|third|last|\d+(?:st|nd|rd|th)|one)\b.{0,36}\b(later|today|tomorrow|morning|afternoon|evening|night|tonight)\b/u',
            $normalized
        ) === 1;

        return ($hasEditVerb && $hasScheduleCue) || $hasStandaloneReorderShape || $implicitEditPhrase || $hasDoIndexedSchedulingPhrase;
    }

    private function findLatestScheduleDraftSourceMessage(TaskAssistantThread $thread, int $excludeAssistantMessageId): ?TaskAssistantMessage
    {
        return $thread->messages()
            ->where('role', MessageRole::Assistant)
            ->where('id', '!=', $excludeAssistantMessageId)
            ->orderByDesc('id')
            ->get()
            ->first(function (TaskAssistantMessage $message): bool {
                $normalized = $this->scheduleDraftMetadataNormalizer->normalizeAndValidate(
                    is_array($message->metadata ?? null) ? $message->metadata : []
                );

                if (! ($normalized['valid'] ?? false)) {
                    Log::debug('task-assistant.schedule_refinement.skip', [
                        'layer' => 'schedule_refinement',
                        'thread_id' => $message->thread_id,
                        'assistant_message_id' => $message->id,
                        'reason_code' => $normalized['reason_code'] ?? 'invalid_schedule_metadata',
                        'repairs' => $normalized['repairs'] ?? [],
                    ]);

                    return false;
                }

                $proposals = is_array($normalized['proposals'] ?? null) ? $normalized['proposals'] : [];

                return $proposals !== [];
            });
    }

    private function assistantMessageHasPendingSchedulableProposals(TaskAssistantMessage $message): bool
    {
        $normalized = $this->scheduleDraftMetadataNormalizer->normalizeAndValidate(
            is_array($message->metadata ?? null) ? $message->metadata : []
        );
        if (! ($normalized['valid'] ?? false)) {
            Log::debug('task-assistant.schedule_refinement.skip', [
                'layer' => 'schedule_refinement',
                'thread_id' => $message->thread_id,
                'assistant_message_id' => $message->id,
                'reason_code' => $normalized['reason_code'] ?? 'invalid_schedule_metadata',
                'repairs' => $normalized['repairs'] ?? [],
            ]);

            return false;
        }
        $proposals = is_array($normalized['proposals'] ?? null) ? $normalized['proposals'] : [];

        return $this->scheduleProposalReferenceService->hasPendingSchedulableProposal($proposals);
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function extractScheduleProposalsFromMessage(TaskAssistantMessage $message): ?array
    {
        $normalized = $this->scheduleDraftMetadataNormalizer->normalizeAndValidate(
            is_array($message->metadata ?? null) ? $message->metadata : []
        );
        if (! ($normalized['valid'] ?? false)) {
            return null;
        }

        return is_array($normalized['proposals'] ?? null) ? $normalized['proposals'] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractPlacementDigestFromMessage(TaskAssistantMessage $message): ?array
    {
        $normalized = $this->scheduleDraftMetadataNormalizer->normalizeAndValidate(
            is_array($message->metadata ?? null) ? $message->metadata : []
        );
        if ($normalized['valid'] ?? false) {
            $digest = data_get($normalized['canonical_data'] ?? null, 'placement_digest');
            if (is_array($digest)) {
                return $digest;
            }
        }

        return null;
    }

    /**
     * @return list<array{id:int,title:string,starts_at:string,ends_at:string,status:string}>
     */
    private function collectPendingScheduleBusyIntervals(TaskAssistantThread $thread, int $excludeAssistantMessageId = 0): array
    {
        $messages = TaskAssistantMessage::query()
            ->where('thread_id', $thread->id)
            ->where('role', MessageRole::Assistant)
            ->when($excludeAssistantMessageId > 0, static function ($query) use ($excludeAssistantMessageId): void {
                $query->where('id', '!=', $excludeAssistantMessageId);
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $busyIntervals = [];
        $seenSlots = [];
        $syntheticId = -1;
        foreach ($messages as $message) {
            if (! $message instanceof TaskAssistantMessage) {
                continue;
            }
            $proposals = $this->extractScheduleProposalsFromMessage($message);
            if (! is_array($proposals) || $proposals === []) {
                continue;
            }

            foreach ($proposals as $proposal) {
                if (! is_array($proposal) || ! SchedulableProposalPolicy::isPendingSchedulable($proposal)) {
                    continue;
                }

                $startRaw = trim((string) ($proposal['start_datetime'] ?? ''));
                $endRaw = trim((string) ($proposal['end_datetime'] ?? ''));
                if ($startRaw === '' || $endRaw === '') {
                    continue;
                }

                try {
                    $start = CarbonImmutable::parse($startRaw)->toIso8601String();
                    $end = CarbonImmutable::parse($endRaw)->toIso8601String();
                } catch (\Throwable) {
                    continue;
                }

                $slotKey = $start.'|'.$end;
                if (isset($seenSlots[$slotKey])) {
                    continue;
                }

                $seenSlots[$slotKey] = true;
                $title = trim((string) ($proposal['title'] ?? ''));
                $busyIntervals[] = [
                    'id' => $syntheticId,
                    'title' => 'pending_schedule: '.($title !== '' ? $title : 'block'),
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'status' => 'scheduled',
                ];
                $syntheticId--;
            }
        }

        return $busyIntervals;
    }

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     * @return array<int, array{entity_type: string, entity_id: int, title: string}>
     */
    private function targetEntitiesFromScheduleProposals(array $proposals): array
    {
        $targets = [];
        foreach ($proposals as $p) {
            if (! is_array($p)) {
                continue;
            }
            $id = (int) ($p['entity_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $type = (string) ($p['entity_type'] ?? '');
            if ($type === '') {
                continue;
            }
            $targets[] = [
                'entity_type' => $type,
                'entity_id' => $id,
                'title' => (string) ($p['title'] ?? ''),
            ];
        }

        return $targets;
    }

    private function runScheduleRefinementFlow(
        TaskAssistantThread $thread,
        TaskAssistantMessage $userMessage,
        TaskAssistantMessage $assistantMessage,
        string $content,
        ExecutionPlan $plan,
    ): void {
        $draftMessageId = (int) ($plan->constraints['schedule_refinement_draft_message_id'] ?? 0);
        $sourceMessage = $draftMessageId > 0
            ? TaskAssistantMessage::query()->where('thread_id', $thread->id)->find($draftMessageId)
            : null;

        Log::info('task-assistant.flow', [
            'layer' => 'flow',
            'flow' => 'schedule_refinement',
            'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessage->id,
            'draft_source_message_id' => $draftMessageId,
        ]);

        if (! $sourceMessage) {
            $this->runGeneralGuidanceFlow($thread, $assistantMessage, $content, $plan);

            return;
        }

        $sourceProposals = $this->extractScheduleProposalsFromMessage($sourceMessage);
        if ($sourceProposals === null) {
            $assistantMessage->update([
                'metadata' => array_merge(
                    is_array($assistantMessage->metadata ?? null) ? $assistantMessage->metadata : [],
                    ['schedule_refinement' => ['skip_reason_code' => 'missing_proposals']]
                ),
            ]);
            Log::info('task-assistant.schedule_refinement.binding', [
                'layer' => 'schedule_refinement',
                'thread_id' => $thread->id,
                'assistant_message_id' => $assistantMessage->id,
                'latest_draft_message_id' => $draftMessageId,
                'latest_draft_refinable' => false,
                'fallback_to_fresh_schedule' => true,
            ]);
            $this->runScheduleFlow($thread, $userMessage, $assistantMessage, $content, $this->buildFreshSchedulePlanFromRefinementPlan($plan));

            return;
        }
        if (! $this->scheduleProposalReferenceService->hasPendingSchedulableProposal($sourceProposals)) {
            Log::info('task-assistant.schedule_refinement.binding', [
                'layer' => 'schedule_refinement',
                'thread_id' => $thread->id,
                'assistant_message_id' => $assistantMessage->id,
                'latest_draft_message_id' => $draftMessageId,
                'latest_draft_refinable' => false,
                'fallback_to_fresh_schedule' => true,
            ]);
            $this->runScheduleFlow($thread, $userMessage, $assistantMessage, $content, $this->buildFreshSchedulePlanFromRefinementPlan($plan));

            return;
        }

        $encoded = json_encode($sourceProposals);
        /** @var array<int, array<string, mixed>> $workingProposals */
        $workingProposals = is_string($encoded) ? json_decode($encoded, true) : [];
        if (! is_array($workingProposals)) {
            $workingProposals = $sourceProposals;
        }

        $proposalsBeforeRefinement = $workingProposals;

        $timezone = (string) ($thread->user->timezone ?: config('app.timezone', 'UTC'));

        $normalizedRefinement = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $content) ?? $content));
        $lastReferencedProposalUuids = $this->conversationState->lastScheduleReferencedProposalUuids($thread);

        $segments = $this->scheduleRefinementClauseSplitter->split($normalizedRefinement);
        if ($segments === []) {
            $segments = [$normalizedRefinement];
        }

        $partialFailureNotes = [];
        $hadSuccessfulEdit = false;
        $llmFallbackAttempted = false;
        $executionPath = count($segments) > 1 ? 'deterministic_multi' : 'single_clause';

        $finalProposals = $workingProposals;
        $referencedProposalUuids = $lastReferencedProposalUuids;
        $spillDigest = null;
        $refinementDayContext = [];
        $pendingBusyIntervals = $this->collectPendingScheduleBusyIntervals($thread, $assistantMessage->id);

        foreach ($segments as $segment) {
            $refinementTarget = $this->scheduleEditTargetResolver->resolvePrimaryTarget(
                $segment,
                $finalProposals,
                $referencedProposalUuids,
            );
            $wantsReorder = $this->scheduleEditLexicon->looksLikeReorder($segment);

            if (($refinementTarget['ambiguous'] ?? true) && ! $wantsReorder) {
                Log::debug('task-assistant.schedule_refinement.signal', [
                    'layer' => 'schedule_refinement',
                    'thread_id' => $thread->id,
                    'assistant_message_id' => $assistantMessage->id,
                    'signal' => 'target_ambiguous',
                    'candidate_titles' => is_array($refinementTarget['candidate_titles'] ?? null) ? $refinementTarget['candidate_titles'] : [],
                ]);
                $llm = $this->tryApplyScheduleRefinementLlmFallback(
                    $thread,
                    $content,
                    $finalProposals,
                    $timezone,
                    $llmFallbackAttempted,
                );
                if ($llm !== null) {
                    $finalProposals = $llm['proposals'];
                    $hadSuccessfulEdit = true;
                    $executionPath = 'llm_fallback';
                    if ($llm['referencedProposalUuids'] !== []) {
                        $referencedProposalUuids = $llm['referencedProposalUuids'];
                    }
                    break;
                }
                $partialFailureNotes[] = trim((string) ($refinementTarget['reason'] ?? 'Please specify which item to edit.'));

                continue;
            }

            if (($refinementTarget['confidence'] ?? 'low') === 'low' && ! $wantsReorder) {
                Log::debug('task-assistant.schedule_refinement.signal', [
                    'layer' => 'schedule_refinement',
                    'thread_id' => $thread->id,
                    'assistant_message_id' => $assistantMessage->id,
                    'signal' => 'target_low_confidence',
                    'candidate_titles' => is_array($refinementTarget['candidate_titles'] ?? null) ? $refinementTarget['candidate_titles'] : [],
                ]);
                $llm = $this->tryApplyScheduleRefinementLlmFallback(
                    $thread,
                    $content,
                    $finalProposals,
                    $timezone,
                    $llmFallbackAttempted,
                );
                if ($llm !== null) {
                    $finalProposals = $llm['proposals'];
                    $hadSuccessfulEdit = true;
                    $executionPath = 'llm_fallback';
                    if ($llm['referencedProposalUuids'] !== []) {
                        $referencedProposalUuids = $llm['referencedProposalUuids'];
                    }
                    break;
                }
                $candidates = is_array($refinementTarget['candidate_titles'] ?? null) ? $refinementTarget['candidate_titles'] : [];
                $candidateText = $candidates !== [] ? ' Possible matches: '.implode(', ', $candidates).'.' : '';
                $partialFailureNotes[] = 'I am not fully sure which schedule item you mean.'.$candidateText.' Please mention first/second/last or part of the title.';

                continue;
            }

            $targetIndexForRefinement = $refinementTarget['index'];
            if (! is_int($targetIndexForRefinement)) {
                $partialFailureNotes[] = 'Could not resolve which schedule row to edit for part of your message.';

                continue;
            }

            $useSpillPlacement = $this->scheduleRefinementPlacementRouter->shouldUseSpillForRefinement(
                $segment,
                $finalProposals,
                $targetIndexForRefinement,
                $timezone,
            );

            if ($useSpillPlacement) {
                $targetProposal = $finalProposals[$targetIndexForRefinement] ?? null;
                $refinementDayOptions = is_array($targetProposal)
                    ? $this->resolveRefinementDayOptions($segment, $targetProposal, $timezone)
                    : [];
                $spill = $this->structuredFlowGenerator->placeRefinementProposalViaSpill(
                    user: $thread->user,
                    userMessage: $segment,
                    workingProposals: $finalProposals,
                    targetIndex: $targetIndexForRefinement,
                    scheduleUserId: (int) $thread->user_id,
                    refinementDayOptions: $refinementDayOptions,
                    pendingBusyIntervals: $pendingBusyIntervals,
                );
                if (! (bool) ($spill['ok'] ?? false)) {
                    $partialFailureNotes[] = 'I could not find a free slot that fits that block in the time you asked for. Try a different part of the day, another day, or give an exact time (for example 8 pm).';

                    continue;
                }
                /** @var array<int, array<string, mixed>> $merged */
                $merged = $spill['merged_proposals'];
                $finalProposals = $merged;
                $hadSuccessfulEdit = true;
                $spillDigest = is_array($spill['digest'] ?? null) ? $spill['digest'] : null;
                if ($refinementDayOptions !== []) {
                    $refinementDayContext = $refinementDayOptions;
                }
                $rowUuid = trim((string) ($finalProposals[$targetIndexForRefinement]['proposal_uuid']
                    ?? $finalProposals[$targetIndexForRefinement]['proposal_id'] ?? ''));
                if ($rowUuid !== '') {
                    $referencedProposalUuids = array_values(array_unique(array_merge(
                        $referencedProposalUuids,
                        [$rowUuid]
                    )));
                }

                continue;
            }

            $resolution = $this->scheduleRefinementIntentResolver->resolveDetailed($segment, $finalProposals, $timezone, $referencedProposalUuids);
            $resolvedOperations = is_array($resolution['operations'] ?? null) ? $resolution['operations'] : [];
            if ((bool) ($resolution['clarification_required'] ?? false)) {
                Log::debug('task-assistant.schedule_refinement.signal', [
                    'layer' => 'schedule_refinement',
                    'thread_id' => $thread->id,
                    'assistant_message_id' => $assistantMessage->id,
                    'signal' => 'pipeline_clarification_required',
                    'reasons' => is_array($resolution['reasons'] ?? null) ? $resolution['reasons'] : [],
                ]);
                $llm = $this->tryApplyScheduleRefinementLlmFallback(
                    $thread,
                    $content,
                    $finalProposals,
                    $timezone,
                    $llmFallbackAttempted,
                );
                if ($llm !== null) {
                    $finalProposals = $llm['proposals'];
                    $hadSuccessfulEdit = true;
                    $executionPath = 'llm_fallback';
                    if ($llm['referencedProposalUuids'] !== []) {
                        $referencedProposalUuids = $llm['referencedProposalUuids'];
                    }
                    break;
                }
                $partialFailureNotes[] = $this->buildRefinementClarificationMessage(
                    resolution: $resolution,
                    fallbackMessage: 'Please tell me which item to edit and the exact change.',
                );

                continue;
            }

            $mutation = $this->scheduleDraftMutationService->applyOperations($finalProposals, $resolvedOperations, $timezone);
            if (! (bool) ($mutation['ok'] ?? false)) {
                $error = trim((string) ($mutation['error'] ?? ''));
                $partialFailureNotes[] = $error !== ''
                    ? 'I could not apply that change because '.$error
                    : 'I could not apply that change yet.';

                continue;
            }

            if ($this->hasSchoolClassConflictInDraft($thread, (array) ($mutation['proposals'] ?? []), $timezone)) {
                $partialFailureNotes[] = 'I could not apply that change because it overlaps your class schedule.';

                continue;
            }

            $finalProposals = $mutation['proposals'];
            $hadSuccessfulEdit = true;
            $extraUuids = $this->proposalUuidsFromScheduleOperations($resolvedOperations);
            if ($extraUuids !== []) {
                $referencedProposalUuids = array_values(array_unique(array_merge(
                    $referencedProposalUuids,
                    $extraUuids
                )));
            }

            if (count((array) ($mutation['changed_proposal_ids'] ?? [])) === 0) {
                $partialFailureNotes[] = 'No schedule change was applied for one part of your message (times may already match or the edit was unclear).';
            }
        }

        if (! $hadSuccessfulEdit && $partialFailureNotes !== []) {
            $clarification = implode(' ', array_values(array_unique($partialFailureNotes)));
            $this->publishScheduleClarificationResponse($thread, $assistantMessage, $proposalsBeforeRefinement, $clarification);
            $targets = $this->targetEntitiesFromScheduleProposals($proposalsBeforeRefinement);
            $this->conversationState->rememberScheduleContext($thread, $targets, $plan->timeWindowHint, $lastReferencedProposalUuids, $assistantMessage->id);

            return;
        }

        $planningNotes = [];
        if ($partialFailureNotes !== []) {
            $planningNotes[] = 'Some parts of your request could not be applied: '.implode(' ', array_values(array_unique($partialFailureNotes)));
        }

        $narrativeUserContent = $content;
        if ($planningNotes !== []) {
            $narrativeUserContent .= "\n\n(Planning note: ".implode(' ', $planningNotes).')';
        }

        Log::info('task-assistant.schedule_refinement.multi', [
            'layer' => 'schedule_refinement',
            'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessage->id,
            'segment_count' => count($segments),
            'execution_path' => $executionPath,
            'had_successful_edit' => $hadSuccessfulEdit,
            'partial_failure_count' => count($partialFailureNotes),
        ]);

        $targets = $this->targetEntitiesFromScheduleProposals($finalProposals);
        $scheduleOptions = [
            'target_entities' => $targets,
            'time_window_hint' => $plan->timeWindowHint,
            'schedule_user_id' => $thread->user_id,
            'refinement_anchor_date' => is_string($refinementDayContext['refinement_anchor_date'] ?? null)
                ? (string) $refinementDayContext['refinement_anchor_date']
                : null,
            'refinement_explicit_day_override' => is_string($refinementDayContext['refinement_explicit_day_override'] ?? null)
                ? (string) $refinementDayContext['refinement_explicit_day_override']
                : null,
        ];

        $dbBuilt = $this->scheduleDbContextBuilder->buildForUser(
            user: $thread->user,
            userMessageContent: $content,
            options: $scheduleOptions,
        );
        $snapshot = $dbBuilt['snapshot'];

        [$context, $contextualSnapshot] = $this->structuredFlowGenerator->buildSchedulePromptContext(
            $snapshot,
            $content,
            $scheduleOptions
        );

        $historyMessages = collect($this->mapToPrismMessages($this->loadHistoryMessages($thread, $userMessage->id)));

        $digest = $this->extractPlacementDigestFromMessage($sourceMessage);
        $digestSource = $spillDigest ?? $digest;
        $recomputedDigest = $this->placementDigestRebuilder->rebuildFromProposals(
            $finalProposals,
            $digestSource
        );
        $digestJson = $recomputedDigest !== null
            ? (json_encode($recomputedDigest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}')
            : null;

        $result = $this->structuredFlowGenerator->composeDailyScheduleFromProposals(
            thread: $thread,
            historyMessages: $historyMessages,
            userMessageContent: $narrativeUserContent,
            proposals: $finalProposals,
            context: $context,
            contextualSnapshot: $contextualSnapshot,
            narrativeGenerationRoute: 'schedule_narrative_followup',
            placementDigestJson: $digestJson,
        );
        $result = $this->enforceRefinementNarrativeConsistency($result, $proposalsBeforeRefinement, $finalProposals);

        $execution = $this->flowExecutionEngine->executeStructuredFlow(
            flow: 'daily_schedule',
            metadataKey: 'schedule',
            thread: $thread,
            assistantMessage: $assistantMessage,
            generationResult: $result,
            assistantFallbackContent: 'I had trouble updating that schedule. Please try rephrasing the change.'
        );

        $this->conversationState->rememberScheduleContext($thread, $targets, $plan->timeWindowHint, $referencedProposalUuids, $assistantMessage->id);
        $this->streamFlowEnvelope(
            thread: $thread,
            assistantMessage: $assistantMessage,
            flow: 'schedule',
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
            'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessage->id,
            'target_entities_count' => count($plan->targetEntities),
            'time_window_hint' => $plan->timeWindowHint,
            ...$this->buildInferenceTelemetry($plan),
        ]);

        $historyMessages = collect($this->mapToPrismMessages($this->loadHistoryMessages($thread, $userMessage->id)));
        $scheduleTargets = $plan->targetEntities;
        $timeWindowHint = $plan->timeWindowHint;
        $namedTaskResolution = is_array($plan->constraints['named_task_resolution'] ?? null)
            ? $plan->constraints['named_task_resolution']
            : [];
        $scheduleSource = (($namedTaskResolution['status'] ?? 'none') === 'single')
            ? 'targeted_schedule'
            : 'schedule';
        $explicitRequestedCount = $this->extractExplicitRequestedCount($content);
        $snapshot = $this->candidateProvider->candidatesForUser(
            $thread->user,
            taskLimit: $this->snapshotTaskLimit(),
        );
        $todoTaskCount = $this->countTodoTasksFromSnapshot($snapshot);
        $doingMeta = $this->collectDoingTasksFromSnapshot($snapshot);

        if ($todoTaskCount === 0 && $doingMeta['count'] > 0) {
            $result = $this->buildDoingOnlyScheduleGenerationResult(
                $doingMeta['titles'],
                $timeWindowHint,
                $plan->countLimit,
            );
        } else {
            $result = $this->structuredFlowGenerator->generateDailySchedule(
                thread: $thread,
                userMessageContent: $content,
                historyMessages: $historyMessages,
                options: [
                    'target_entities' => $scheduleTargets,
                    'schedule_source' => $scheduleSource,
                    'time_window_hint' => $timeWindowHint,
                    'count_limit' => $plan->countLimit,
                    'explicit_requested_count' => $explicitRequestedCount,
                    'is_strict_set_contract' => (bool) ($plan->constraints['is_strict_set_contract'] ?? false),
                    'schedule_user_id' => $thread->user_id,
                    'refinement_anchor_date' => is_string($plan->constraints['refinement_anchor_date'] ?? null)
                        ? (string) $plan->constraints['refinement_anchor_date']
                        : null,
                    'refinement_explicit_day_override' => is_string($plan->constraints['refinement_explicit_day_override'] ?? null)
                        ? (string) $plan->constraints['refinement_explicit_day_override']
                        : null,
                ]
            );
        }
        $result = $this->maybeConvertToScheduleFallbackConfirmation(
            thread: $thread,
            userMessageContent: $content,
            plan: $plan,
            generationResult: $result,
        );
        $result = $this->scheduleFallbackConfirmationService->finalize(
            generationData: $result,
            confirmationRequired: (bool) data_get($result, 'data.confirmation_required', false),
        )['data'];

        $execution = $this->flowExecutionEngine->executeStructuredFlow(
            flow: 'daily_schedule',
            metadataKey: 'schedule',
            thread: $thread,
            assistantMessage: $assistantMessage,
            generationResult: $result,
            assistantFallbackContent: 'I had trouble scheduling these items. Please try again with more details.'
        );

        $referencedProposalUuids = [];
        $genData = is_array($result['data'] ?? null) ? $result['data'] : [];
        $proposals = is_array($genData['proposals'] ?? null) ? $genData['proposals'] : [];
        $confirmationRequired = (bool) ($genData['confirmation_required'] ?? false);
        if ($confirmationRequired) {
            $this->conversationState->rememberPendingScheduleFallback(
                thread: $thread,
                scheduleData: $genData,
                timeWindowHint: $timeWindowHint,
                initialUserMessage: $content,
            );
        } else {
            $this->conversationState->clearPendingScheduleFallback($thread);
        }

        // Store only schedulable (editable) proposal UUIDs so pronoun-based edits
        // (it/this/that) can resolve even for single-target schedules.
        $referencedProposalUuids = $this->scheduleProposalReferenceService->collectReferencedPendingSchedulableUuids($proposals);

        if (! $confirmationRequired) {
            $this->conversationState->rememberScheduleContext(
                $thread,
                $scheduleTargets,
                $timeWindowHint,
                $referencedProposalUuids,
                $assistantMessage->id,
            );
        }
        $this->streamFlowEnvelope(
            thread: $thread,
            assistantMessage: $assistantMessage,
            flow: 'schedule',
            execution: $execution
        );
    }

    /**
     * Resolve day anchoring for a refinement edit:
     * - Default: inherit the target row's original date.
     * - Explicit day cues (today/tomorrow/date) override.
     * - Relative "later + daypart" phrases intentionally imply today.
     *
     * @param  array<string, mixed>  $targetProposal
     * @return array<string, string>
     */
    private function resolveRefinementDayOptions(string $segment, array $targetProposal, string $timezone): array
    {
        $tz = $timezone !== '' ? $timezone : (string) config('app.timezone', 'Asia/Manila');
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $segment) ?? $segment));
        if ($normalized === '') {
            return [];
        }

        $explicitDate = $this->scheduleEditTemporalParser->parseLocalDateYmd($normalized, $tz);
        if ($explicitDate !== null) {
            return [
                'refinement_explicit_day_override' => $explicitDate,
            ];
        }

        if ($this->isRelativeLaterDaypartTodayOverride($normalized)) {
            return [
                'refinement_explicit_day_override' => CarbonImmutable::now($tz)->toDateString(),
            ];
        }

        $startRaw = trim((string) ($targetProposal['start_datetime'] ?? ''));
        if ($startRaw === '') {
            return [];
        }

        try {
            $anchorDate = CarbonImmutable::parse($startRaw, $tz)->toDateString();
        } catch (\Throwable) {
            return [];
        }

        return [
            'refinement_anchor_date' => $anchorDate,
        ];
    }

    private function isRelativeLaterDaypartTodayOverride(string $normalized): bool
    {
        if (preg_match('/\blater\b/u', $normalized) !== 1) {
            return false;
        }

        return preg_match('/\b(morning|afternoon|evening|night|tonight)\b/u', $normalized) === 1;
    }

    /**
     * When the user asks to schedule but there is no multiturn listing and no resolved targets, run a ranked list first.
     * If the message anchors a concrete calendar horizon (today/tomorrow/week/etc.), run prioritize+schedule instead.
     */
    private function maybeRemapScheduleToPrioritize(TaskAssistantThread $thread, ExecutionPlan $plan, string $userMessageContent): ExecutionPlan
    {
        if ($plan->clarificationNeeded) {
            return $plan;
        }
        if ($plan->flow !== 'schedule') {
            return $plan;
        }
        if ($plan->targetEntities !== []) {
            return $plan;
        }
        if ($this->conversationState->lastListing($thread) !== null) {
            return $plan;
        }
        if ($this->isLikelyScheduleRefinementEditPrompt($userMessageContent)) {
            return $plan;
        }

        if ($this->isLikelyFreshDayPlanningPrompt($userMessageContent)) {
            $reasonCodes = array_values(array_unique(array_merge(
                $plan->reasonCodes,
                [TaskAssistantReasonCodes::SCHEDULE_PROMOTED_PRIORITIZE_SCHEDULE_DAY_PLANNING]
            )));

            return new ExecutionPlan(
                flow: 'prioritize_schedule',
                confidence: $plan->confidence,
                clarificationNeeded: $plan->clarificationNeeded,
                clarificationQuestion: $plan->clarificationQuestion,
                reasonCodes: $reasonCodes,
                constraints: $plan->constraints,
                targetEntities: $plan->targetEntities,
                timeWindowHint: $plan->timeWindowHint,
                countLimit: $plan->countLimit,
                generationProfile: 'schedule',
            );
        }

        $snapshot = $this->candidateProvider->candidatesForUser($thread->user, taskLimit: $this->snapshotTaskLimit());
        $timezone = (string) ($snapshot['timezone'] ?? config('app.timezone', 'UTC'));
        $now = CarbonImmutable::now($timezone);
        $horizon = $this->scheduleHorizonResolver->resolve($userMessageContent, $timezone, $now);
        $horizonLabel = (string) ($horizon['label'] ?? 'default_today');

        if ($horizonLabel !== 'default_today') {
            $reasonCodes = array_values(array_unique(array_merge(
                $plan->reasonCodes,
                [TaskAssistantReasonCodes::SCHEDULE_PROMOTED_PRIORITIZE_SCHEDULE_EXPLICIT_HORIZON]
            )));

            return new ExecutionPlan(
                flow: 'prioritize_schedule',
                confidence: $plan->confidence,
                clarificationNeeded: $plan->clarificationNeeded,
                clarificationQuestion: $plan->clarificationQuestion,
                reasonCodes: $reasonCodes,
                constraints: $plan->constraints,
                targetEntities: $plan->targetEntities,
                timeWindowHint: $plan->timeWindowHint,
                countLimit: $plan->countLimit,
                generationProfile: 'schedule',
            );
        }

        $reasonCodes = array_values(array_unique(array_merge(
            $plan->reasonCodes,
            [TaskAssistantReasonCodes::SCHEDULE_REROUTED_NO_LISTING_CONTEXT]
        )));

        return new ExecutionPlan(
            flow: 'prioritize',
            confidence: $plan->confidence,
            clarificationNeeded: $plan->clarificationNeeded,
            clarificationQuestion: $plan->clarificationQuestion,
            reasonCodes: $reasonCodes,
            constraints: $plan->constraints,
            targetEntities: $plan->targetEntities,
            timeWindowHint: $plan->timeWindowHint,
            countLimit: $plan->countLimit,
            generationProfile: 'prioritize',
        );
    }

    private function isLikelyFreshDayPlanningPrompt(string $content): bool
    {
        $normalized = mb_strtolower(trim($content));
        if ($normalized === '') {
            return false;
        }

        if ($this->isLikelyScheduleRefinementEditPrompt($normalized)) {
            return false;
        }

        $hasDayPlanningCue = preg_match(
            '/\b(plan|schedule|organi[sz]e|map\s*out|set\s*up)\b.{0,45}\b(my|the)\s+(whole\s+)?day\b/u',
            $normalized
        ) === 1;
        $hasScheduleAllCue = preg_match(
            '/\b(schedule|plan)\b.{0,45}\b(all|everything)\b.{0,30}\b(tasks?|important|priority)\b/u',
            $normalized
        ) === 1;
        $hasWhenToDoCue = preg_match(
            '/\b(when\s+should\s+i\s+do|fit\s+in|time\s+block|calendar|later\s+today|later)\b/u',
            $normalized
        ) === 1;

        return $hasDayPlanningCue || $hasScheduleAllCue || ($hasWhenToDoCue && str_contains($normalized, 'day'));
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
            ...$this->buildInferenceTelemetry($plan),
        ]);

        if (
            in_array(TaskAssistantReasonCodes::GENERAL_GUIDANCE_GREETING_ONLY_DETERMINISTIC, $plan->reasonCodes, true)
            || in_array(TaskAssistantReasonCodes::GENERAL_GUIDANCE_GREETING_ONLY, $plan->reasonCodes, true)
        ) {
            $greetingPayload = $this->buildDeterministicGreetingGuidancePayload($thread);
            $generationResult = [
                'valid' => true,
                'data' => $greetingPayload,
                'errors' => [],
            ];

            $execution = $this->flowExecutionEngine->executeStructuredFlow(
                flow: 'general_guidance',
                metadataKey: 'general_guidance',
                thread: $thread,
                assistantMessage: $assistantMessage,
                generationResult: $generationResult,
                assistantFallbackContent: (string) ($greetingPayload['message'] ?? "Hi, I'm TaskLyst. We can start with one clear step today."),
            );

            $this->conversationState->clearPendingGeneralGuidance($thread);
            $this->streamFlowEnvelope(
                thread: $thread,
                assistantMessage: $assistantMessage,
                flow: 'general_guidance',
                execution: $execution
            );

            return;
        }

        if (in_array(TaskAssistantReasonCodes::GENERAL_GUIDANCE_CLOSING_ONLY, $plan->reasonCodes, true)) {
            $closingPayload = $this->buildDeterministicClosingGuidancePayload($thread, $userMessage);
            $generationResult = [
                'valid' => true,
                'data' => $closingPayload,
                'errors' => [],
            ];

            $execution = $this->flowExecutionEngine->executeStructuredFlow(
                flow: 'general_guidance',
                metadataKey: 'general_guidance',
                thread: $thread,
                assistantMessage: $assistantMessage,
                generationResult: $generationResult,
                assistantFallbackContent: (string) ($closingPayload['message'] ?? 'You are doing great. I can help again whenever you are ready.'),
            );

            $this->conversationState->clearPendingGeneralGuidance($thread);
            $this->streamFlowEnvelope(
                thread: $thread,
                assistantMessage: $assistantMessage,
                flow: 'general_guidance',
                execution: $execution
            );

            return;
        }

        if (in_array(TaskAssistantReasonCodes::INTENT_OFF_TOPIC, $plan->reasonCodes, true)) {
            // Strong guardrail to keep Hermes in the task assistant domain even
            // when users ask unrelated questions (relationships, politics, product
            // recommendations, etc.). We still require the general_guidance schema.
            $userMessage .= "\n\nOFF_TOPIC_GUARDRAIL: This request is off-topic for a task assistant. Acknowledge briefly, refuse to help with the unrelated topic, and suggest task-focused next steps (prioritize tasks or schedule time blocks) while following the current general_guidance schema.";
        }

        if (in_array(TaskAssistantReasonCodes::GENERAL_GUIDANCE_GREETING_ONLY, $plan->reasonCodes, true)) {
            // Greeting-only prompts should not assume tasks exist or pull list details.
            $userMessage .= "\n\nGREETING_GUARDRAIL: The user only greeted (hello/hi/yo). Do not assume they want task suggestions yet. Do not reference their list data, deadlines, priorities, or specific task titles. Introduce TaskLyst, say you can prioritize tasks or schedule time blocks, and offer neutral next actions.";
        }

        $guidance = $this->generalGuidanceService->generateGeneralGuidance(
            user: $thread->user,
            userMessage: $userMessage,
            forcedMode: in_array(TaskAssistantReasonCodes::INTENT_OFF_TOPIC, $plan->reasonCodes, true) ? 'off_topic' : null,
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
                'next_options' => (string) ($guidance['next_options'] ?? ''),
                'next_options_chip_texts' => is_array($guidance['next_options_chip_texts'] ?? null)
                    ? array_values($guidance['next_options_chip_texts'])
                    : [],
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
     * @return array{
     *   intent: string,
     *   acknowledgement: string,
     *   message: string,
     *   suggested_next_actions: list<string>,
     *   next_options: string,
     *   next_options_chip_texts: list<string>,
     *   subtype: string
     * }
     */
    private function buildDeterministicClosingGuidancePayload(TaskAssistantThread $thread, string $userMessage): array
    {
        $normalized = mb_strtolower(trim($userMessage));
        $state = $this->conversationState->get($thread);
        $lastFlow = trim((string) ($state['last_flow'] ?? ''));

        $acknowledgement = (string) config(
            'task-assistant.closing.response.acknowledgement',
            'You are welcome.'
        );
        $message = (string) config(
            'task-assistant.closing.response.message',
            'Nice work staying consistent today. You are building momentum one step at a time.'
        );
        $nextOptions = (string) config(
            'task-assistant.closing.response.next_options',
            'If you want, I can help again anytime to prioritize your next tasks or block time for them.'
        );

        if (preg_match('/\b(bye|goodbye|see\s+you|see\s+ya|later|good\s*night|take\s*care)\b/u', $normalized) === 1) {
            $acknowledgement = (string) config(
                'task-assistant.closing.response.goodbye_acknowledgement',
                'Take care, and great job today.'
            );
        }

        if (in_array($lastFlow, ['prioritize', 'prioritize_schedule', 'schedule'], true)) {
            $message = (string) config(
                'task-assistant.closing.response.message_after_planning',
                'You have a clear plan now. Keep going one block at a time and you will make steady progress.'
            );
        }

        $dynamicChips = $this->quickChipResolver->resolveForEmptyState(
            user: $thread->user,
            thread: $thread,
            limit: 4,
        );
        $dynamicChips = $this->quickChipResolver->filterContinueStyleQuickChips($dynamicChips);
        $dynamicChips = array_values(array_slice($dynamicChips, 0, 3));
        if (count($dynamicChips) < 3) {
            $fallbackChips = ['What should I do first', 'Schedule my most important task', 'Create a plan for today'];
            while (count($dynamicChips) < 3) {
                $dynamicChips[] = $fallbackChips[count($dynamicChips)];
            }
        }

        return [
            'intent' => 'task',
            'acknowledgement' => trim($acknowledgement),
            'message' => trim($message),
            'suggested_next_actions' => [
                'Prioritize my next tasks.',
                'Schedule time blocks for my next tasks.',
            ],
            'next_options' => trim($nextOptions),
            'next_options_chip_texts' => $dynamicChips,
            'subtype' => 'closing',
        ];
    }

    /**
     * @return array{
     *   intent: string,
     *   acknowledgement: string,
     *   message: string,
     *   suggested_next_actions: list<string>,
     *   next_options: string,
     *   next_options_chip_texts: list<string>,
     *   subtype: string
     * }
     */
    private function buildDeterministicGreetingGuidancePayload(TaskAssistantThread $thread): array
    {
        $dynamicChips = $this->quickChipResolver->resolveForEmptyState(
            user: $thread->user,
            thread: $thread,
            limit: 4,
        );
        $dynamicChips = $this->quickChipResolver->filterContinueStyleQuickChips($dynamicChips);
        $dynamicChips = array_values(array_slice($dynamicChips, 0, 3));
        if ($dynamicChips === []) {
            $dynamicChips = ['What should I do first', 'Schedule my tasks', 'Prioritize then schedule my tasks'];
        }

        return [
            'intent' => 'task',
            'acknowledgement' => (string) config(
                'task-assistant.greeting.response.acknowledgement',
                "Hi, I'm TaskLyst—your task assistant."
            ),
            'message' => (string) config(
                'task-assistant.greeting.response.message',
                'Great to have you here. Small focused steps today can build strong momentum.'
            ),
            'suggested_next_actions' => [
                'Prioritize my tasks.',
                'Schedule time blocks for my tasks.',
            ],
            'next_options' => (string) config(
                'task-assistant.greeting.response.next_options',
                'If you want, we can rank what to do first, schedule your tasks, or do both in one pass.'
            ),
            'next_options_chip_texts' => $dynamicChips,
            'subtype' => 'greeting',
        ];
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
     * @return array<int, UserMessage|AssistantMessage>
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
                $out[] = new AssistantMessage($msg->content ?? '');

                continue;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     */
    private function publishScheduleClarificationResponse(
        TaskAssistantThread $thread,
        TaskAssistantMessage $assistantMessage,
        array $proposals,
        string $clarification,
        ?array $examples = null,
    ): void {
        $blocks = [];
        $items = [];
        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            $start = (string) ($proposal['start_datetime'] ?? '');
            $end = (string) ($proposal['end_datetime'] ?? '');
            $title = (string) ($proposal['title'] ?? 'Item');

            $items[] = [
                'title' => $title,
                'entity_type' => (string) ($proposal['entity_type'] ?? ''),
                'entity_id' => (int) ($proposal['entity_id'] ?? 0),
                'start_datetime' => $start,
                'end_datetime' => $end,
                'duration_minutes' => (int) ($proposal['duration_minutes'] ?? 0),
            ];

            $startTime = '';
            $endTime = '';
            try {
                if ($start !== '') {
                    $startTime = (new \DateTimeImmutable($start))->format('H:i');
                }
                if ($end !== '') {
                    $endTime = (new \DateTimeImmutable($end))->format('H:i');
                }
            } catch (\Throwable) {
                $startTime = '';
                $endTime = '';
            }

            if ($startTime !== '' && $endTime !== '') {
                $blocks[] = [
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'task_id' => ((string) ($proposal['entity_type'] ?? '') === 'task') ? (int) ($proposal['entity_id'] ?? 0) : null,
                    'event_id' => ((string) ($proposal['entity_type'] ?? '') === 'event') ? (int) ($proposal['entity_id'] ?? 0) : null,
                    'label' => $title,
                    'note' => 'Draft block preserved while awaiting clarification.',
                ];
            }
        }

        $defaultExamples = [
            'move second to 8 pm',
            'move quiz task to tomorrow 8 pm',
        ];
        $exampleList = is_array($examples) ? $examples : $defaultExamples;
        $exampleList = array_values(array_filter(array_map(
            static fn (mixed $example): string => trim((string) $example),
            $exampleList
        ), static fn (string $example): bool => $example !== ''));
        if ($exampleList === []) {
            $exampleList = $defaultExamples;
        }
        if (count($exampleList) === 1) {
            $exampleList[] = $defaultExamples[1];
        }
        $clarification = $this->applyScheduleClarificationVariation($thread, trim($clarification));
        $content = trim($clarification).sprintf(
            ' For example: "%s" or "%s".',
            $exampleList[0],
            $exampleList[1]
        );
        $data = [
            'schema_version' => ScheduleDraftMetadataNormalizer::SCHEMA_VERSION,
            'proposals' => $proposals,
            'blocks' => $blocks,
            'items' => $items,
            'schedule_variant' => 'daily',
            'framing' => $content,
            'reasoning' => null,
            'confirmation' => 'Tell me the exact item and change, and I will update it.',
            'schedule_empty_placement' => false,
            'placement_digest' => [],
            'window_selection_explanation' => '',
            'ordering_rationale' => [],
            'blocking_reasons' => [],
            'fallback_choice_explanation' => null,
        ];
        $normalized = $this->scheduleDraftMetadataNormalizer->normalizeAndValidate(['schedule' => $data, 'structured' => ['data' => $data]]);
        $canonicalData = is_array($normalized['canonical_data'] ?? null) ? $normalized['canonical_data'] : $data;

        $assistantMessage->update([
            'content' => $content,
            'metadata' => array_merge(
                is_array($assistantMessage->metadata ?? null) ? $assistantMessage->metadata : [],
                [
                    'schedule' => $canonicalData,
                    'structured' => ['data' => $canonicalData],
                ]
            ),
        ]);

        $this->streamFinalAssistantJson(
            $thread->user_id,
            $assistantMessage,
            $this->buildJsonEnvelope(
                flow: 'schedule',
                data: $canonicalData,
                threadId: $thread->id,
                assistantMessageId: $assistantMessage->id,
                ok: true,
            )
        );
    }

    /**
     * @param  array<string, mixed>  $resolution
     */
    private function buildRefinementClarificationMessage(
        array $resolution,
        string $fallbackMessage,
    ): string {
        $base = trim((string) ($resolution['clarification_message'] ?? $fallbackMessage));
        $context = is_array($resolution['clarification_context'] ?? null)
            ? $resolution['clarification_context']
            : [];

        if ($context === []) {
            return $base !== '' ? $base : $fallbackMessage;
        }

        $targetSummary = trim((string) ($context['target_summary'] ?? ''));
        $parsedDate = trim((string) ($context['parsed_date_ymd'] ?? ''));
        $parsedTime = trim((string) ($context['parsed_time_hhmm'] ?? ''));
        $parsedDaypart = trim((string) ($context['parsed_part_of_day_hhmm'] ?? ''));
        $candidates = is_array($context['candidate_titles'] ?? null) ? $context['candidate_titles'] : [];

        $understoodParts = [];
        if ($targetSummary !== '' && $targetSummary !== 'unresolved target') {
            $understoodParts[] = 'target: '.$targetSummary;
        }
        if ($parsedDate !== '') {
            $understoodParts[] = 'date: '.$parsedDate;
        }
        $timeToUse = $parsedTime !== '' ? $parsedTime : $parsedDaypart;
        if ($timeToUse !== '') {
            $understoodParts[] = 'time: '.$this->formatHhmmForClarification($timeToUse);
        }

        $prefix = $understoodParts !== []
            ? 'I understood '.implode(', ', $understoodParts).'. '
            : '';
        $candidateHint = $candidates !== []
            ? ' If you mean a different item, mention the exact title or position.'
            : '';

        return trim($prefix.$base.$candidateHint);
    }

    private function formatHhmmForClarification(string $hhmm): string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $matches) !== 1) {
            return $hhmm;
        }
        $hour24 = (int) ($matches[1] ?? 0);
        $minute = (int) ($matches[2] ?? 0);
        if ($hour24 < 0 || $hour24 > 23 || $minute < 0 || $minute > 59) {
            return $hhmm;
        }
        $ampm = $hour24 >= 12 ? 'PM' : 'AM';
        $hour12 = $hour24 % 12;
        if ($hour12 === 0) {
            $hour12 = 12;
        }

        return $hour12.':'.str_pad((string) $minute, 2, '0', STR_PAD_LEFT).' '.$ampm;
    }

    private function applyScheduleClarificationVariation(TaskAssistantThread $thread, string $message): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);
        if ($normalized === '') {
            return '';
        }

        $state = $this->conversationState->get($thread);
        $previousHash = trim((string) ($state['schedule_refinement']['last_clarification_hash'] ?? ''));
        $currentHash = sha1(mb_strtolower($normalized));
        $repeatCount = (int) ($state['schedule_refinement']['clarification_repeat_count'] ?? 0);
        if ($previousHash === $currentHash) {
            $repeatCount++;
        } else {
            $repeatCount = 0;
        }

        $state['schedule_refinement']['last_clarification_hash'] = $currentHash;
        $state['schedule_refinement']['clarification_repeat_count'] = $repeatCount;
        $this->conversationState->put($thread, $state);

        if ($repeatCount <= 0) {
            return $normalized;
        }

        $variations = [
            ' You can also say: "move #2 to 8 pm tomorrow".',
            ' Quick format: "move <item> to <time> <day>".',
            ' I can apply it immediately once you share item + exact time/day.',
        ];
        $suffix = $variations[min($repeatCount - 1, count($variations) - 1)];

        return $normalized.$suffix;
    }

    /**
     * @param  array{valid: bool, data: array<string, mixed>, errors: array<int, string>}  $result
     * @param  array<int, array<string, mixed>>  $beforeProposals
     * @param  array<int, array<string, mixed>>  $afterProposals
     * @return array{valid: bool, data: array<string, mixed>, errors: array<int, string>}
     */
    /**
     * @param  array<int, array<string, mixed>>  $workingProposals
     * @return array{proposals: array<int, array<string, mixed>>, referencedProposalUuids: list<string>}|null
     */
    private function tryApplyScheduleRefinementLlmFallback(
        TaskAssistantThread $thread,
        string $originalUserContent,
        array $workingProposals,
        string $timezone,
        bool &$llmFallbackAttempted,
    ): ?array {
        if ($llmFallbackAttempted) {
            return null;
        }

        // Deterministic-first refinement: skip LLM operation extraction fallback.
        $llmFallbackAttempted = true;
        Log::debug('task-assistant.schedule_refinement.llm_fallback_disabled', [
            'layer' => 'schedule_refinement',
            'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
            'thread_id' => $thread->id,
            'assistant_message_id' => app()->bound('task_assistant.message_id') ? app('task_assistant.message_id') : null,
        ]);

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $operations
     * @return list<string>
     */
    private function proposalUuidsFromScheduleOperations(array $operations): array
    {
        $uuids = [];
        foreach ($operations as $op) {
            if (! is_array($op)) {
                continue;
            }
            $u = trim((string) ($op['proposal_uuid'] ?? ''));
            if ($u !== '') {
                $uuids[] = $u;
            }
            $a = trim((string) ($op['anchor_proposal_uuid'] ?? ''));
            if ($a !== '') {
                $uuids[] = $a;
            }
        }

        return array_values(array_unique(array_filter($uuids, static fn (string $id): bool => $id !== '')));
    }

    private function enforceRefinementNarrativeConsistency(array $result, array $beforeProposals, array $afterProposals): array
    {
        $beforeEncoded = json_encode($beforeProposals);
        $afterEncoded = json_encode($afterProposals);
        $changed = is_string($beforeEncoded) && is_string($afterEncoded)
            ? $beforeEncoded !== $afterEncoded
            : $beforeProposals !== $afterProposals;

        if ($changed || $result['valid'] !== true) {
            return $result;
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        if (trim((string) ($data['framing'] ?? '')) === '') {
            $data['framing'] = 'I kept your current schedule draft unchanged.';
        }
        if (trim((string) ($data['reasoning'] ?? '')) === '') {
            $data['reasoning'] = 'I need a more specific edit target before changing times.';
        }
        if (trim((string) ($data['confirmation'] ?? '')) === '') {
            $data['confirmation'] = 'Tell me exactly which item to edit (first, second, last, or title) and the new time/date/duration.';
        }
        $result['data'] = $data;

        return $result;
    }

    /**
     * @param  array{valid: bool, data: array<string, mixed>, errors: array<int, string>}  $generationResult
     * @return array{valid: bool, data: array<string, mixed>, errors: array<int, string>}
     */
    private function maybeConvertToScheduleFallbackConfirmation(
        TaskAssistantThread $thread,
        string $userMessageContent,
        ExecutionPlan $plan,
        array $generationResult,
    ): array {
        $data = is_array($generationResult['data'] ?? null) ? $generationResult['data'] : [];
        if ($data === []) {
            return $generationResult;
        }

        if (! $this->shouldRequireFallbackConfirmation($plan, $data)) {
            return $generationResult;
        }

        $data = $this->buildScheduleFallbackConfirmationData($data, $thread, $userMessageContent, $plan);
        $generationResult['data'] = $data;

        return $generationResult;
    }

    /**
     * @param  array<string, mixed>  $scheduleData
     */
    private function shouldRequireFallbackConfirmation(ExecutionPlan $plan, array $scheduleData): bool
    {
        if ($this->isDefaultAsapAutoSpillSuccess($scheduleData)) {
            return false;
        }

        if ($this->scheduleFallbackPolicy->shouldRequireConfirmation($plan, $scheduleData)) {
            return true;
        }

        if ($plan->timeWindowHint !== 'later') {
            $strictDate = $this->strictRequestedDateFromScheduleData($scheduleData);
            if ($strictDate !== null) {
                return $this->requiresStrictDateFallbackConfirmation($scheduleData, $strictDate);
            }

            return false;
        }

        return false;
    }

    /**
     * Generic schedule prompts (no explicit day/time) should auto-propose when default auto-spill
     * found at least one valid placement within the expanded horizon.
     *
     * @param  array<string, mixed>  $scheduleData
     */
    private function isDefaultAsapAutoSpillSuccess(array $scheduleData): bool
    {
        $digest = is_array($scheduleData['placement_digest'] ?? null) ? $scheduleData['placement_digest'] : [];
        if (! (bool) ($digest['default_asap_mode'] ?? false)) {
            return false;
        }

        $attemptedHorizon = is_array($digest['attempted_horizon'] ?? null) ? $digest['attempted_horizon'] : [];
        if (trim((string) ($attemptedHorizon['label'] ?? '')) !== 'default_asap_spread') {
            return false;
        }

        $proposals = is_array($scheduleData['proposals'] ?? null) ? $scheduleData['proposals'] : [];

        return count($proposals) > 0;
    }

    /**
     * @param  array<string, mixed>  $scheduleData
     * @return array<string, mixed>
     */
    private function buildScheduleFallbackConfirmationData(
        array $scheduleData,
        TaskAssistantThread $thread,
        string $userMessageContent,
        ExecutionPlan $plan,
    ): array {
        $digest = is_array($scheduleData['placement_digest'] ?? null) ? $scheduleData['placement_digest'] : [];
        $placementDates = is_array($digest['placement_dates'] ?? null) ? $digest['placement_dates'] : [];
        $daysUsed = is_array($digest['days_used'] ?? null) ? $digest['days_used'] : [];
        $requestedCount = max(1, (int) ($digest['requested_count'] ?? 1));
        $topNShortfall = (bool) ($digest['top_n_shortfall'] ?? false);
        $firstDate = is_string($placementDates[0] ?? null) ? $placementDates[0] : null;
        $datePhrase = $firstDate !== null ? CarbonImmutable::parse($firstDate)->format('M j, Y') : 'tomorrow';
        $requestedWindow = $this->requestedWindowDescriptorFromScheduleData($scheduleData);
        $requestedWindowLabel = (string) ($requestedWindow['label'] ?? 'your requested window');
        $proposalsCount = is_array($scheduleData['proposals'] ?? null) ? count($scheduleData['proposals']) : 0;
        $strictDate = $this->strictRequestedDateFromScheduleData($scheduleData);
        $requestedCountSource = $this->detectRequestedCountSourceForConfirmation($userMessageContent, $scheduleData);
        $attemptedHorizon = $this->attemptedHorizonDescriptorFromScheduleData($scheduleData);
        $fallbackHorizon = $this->fallbackHorizonDescriptorFromScheduleData($scheduleData);
        $reasonDetails = $this->scheduleFallbackReasonExplainer->summarize($scheduleData, $plan->timeWindowHint);

        $signals = is_array($digest['confirmation_signals'] ?? null) ? $digest['confirmation_signals'] : [];
        $triggers = is_array($signals['triggers'] ?? null) ? $signals['triggers'] : [];
        $nearestAvailableWindow = is_array($signals['nearest_available_window'] ?? null)
            ? $signals['nearest_available_window']
            : null;
        $nearestDaypartLabel = $this->buildNearestWindowDaypartLabel($nearestAvailableWindow);
        $nearestPromptLabel = $nearestDaypartLabel !== '' ? $nearestDaypartLabel : 'the closest available daypart';
        $nearestActionLabel = $nearestDaypartLabel !== '' ? "Schedule for {$nearestDaypartLabel}" : 'Schedule for the closest available daypart';
        $hasNearestWindow = $nearestDaypartLabel !== '';

        $hasDraftToKeep = $proposalsCount > 0;
        $defaultOptions = $hasDraftToKeep
            ? ['Continue with that plan', 'Try another time window']
            : [$nearestActionLabel, 'Try another time window'];

        if ($strictDate !== null) {
            $datePhrase = CarbonImmutable::parse($strictDate)->format('M j, Y');
            $reasonMessage = "I could not keep every placement on {$datePhrase} with the current constraints.";
            $prompt = "I can schedule this for {$nearestPromptLabel}, or you can pick another time window. What do you want?";
            $reasonCode = 'explicit_day_not_feasible';
            $options = [
                $nearestActionLabel,
                'Try another time window',
            ];
            $optionActions = [
                ['id' => 'try_nearest_available_window', 'label' => $nearestActionLabel],
                ['id' => 'pick_another_time_window', 'label' => 'Try another time window'],
            ];
        } elseif ($topNShortfall) {
            $taskNoun = $proposalsCount === 1 ? 'task' : 'tasks';
            if ($requestedCountSource === 'explicit_user') {
                $reasonMessage = "You asked for top {$requestedCount}, but only {$proposalsCount} fit in {$requestedWindowLabel}.";
            } else {
                $reasonMessage = "Only {$proposalsCount} fit in {$requestedWindowLabel} for this draft.";
            }
            $prompt = "I can keep this draft with {$proposalsCount} {$taskNoun}, or we can try another time window to fit all {$requestedCount}. Which do you prefer?";
            $reasonCode = 'top_n_shortfall';
            $options = [
                'Continue with that plan',
                'Try another time window',
            ];
            $optionActions = [
                ['id' => 'use_current_draft', 'label' => 'Continue with that plan'],
                ['id' => 'pick_another_time_window', 'label' => 'Try another time window'],
            ];
        } elseif (in_array('empty_placement', $triggers, true)) {
            $reasonCode = 'empty_placement_no_fit';
            $reasonMessage = "I could not find open time that fits {$requestedWindowLabel} with your classes and events as they are.";
            $prompt = $hasNearestWindow
                ? "I can try {$nearestPromptLabel}, or widen your time window. What would you prefer?"
                : 'I can try the closest available window, or widen your time window. What would you prefer?';
            $options = $defaultOptions;
            $optionActions = [
                ['id' => 'try_nearest_available_window', 'label' => $nearestActionLabel],
                ['id' => 'pick_another_time_window', 'label' => 'Try another time window'],
            ];
        } elseif (in_array('adaptive_relaxed_placement', $triggers, true)) {
            $reasonCode = 'adaptive_relaxed_placement';
            $reasonMessage = 'Your first-choice window was too tight, so I drafted times on another part of the day or the next day.';
            $prompt = "I can keep this draft starting around {$datePhrase}, or we can try different times. What works for you?";
            $options = [
                'Continue with that plan',
                'Try another time window',
            ];
            $optionActions = [
                ['id' => 'use_current_draft', 'label' => 'Continue with that plan'],
                ['id' => 'pick_another_time_window', 'label' => 'Try another time window'],
            ];
        } elseif (in_array('requested_window_unsatisfied', $triggers, true) || in_array('hinted_window_unsatisfied', $triggers, true)) {
            $reasonCode = 'alternative_outside_requested_window';
            $reasonMessage = "The best open slots I found do not fall inside {$requestedWindowLabel}.";
            $prompt = 'Do you want to continue with that plan, or pick another time this week?';
            $options = $defaultOptions;
            $optionActions = [
                ['id' => $hasDraftToKeep ? 'use_current_draft' : 'try_nearest_available_window', 'label' => $hasDraftToKeep ? 'Continue with that plan' : $nearestActionLabel],
                ['id' => 'pick_another_time_window', 'label' => 'Try another time window'],
            ];
        } elseif (in_array('placement_outside_horizon', $triggers, true)) {
            $reasonCode = 'placement_outside_horizon';
            $reasonMessage = 'The draft uses at least one time outside the day range you originally asked for.';
            $prompt = 'Should I continue with that plan, or pick another time this week?';
            $options = $defaultOptions;
            $optionActions = [
                ['id' => $hasDraftToKeep ? 'use_current_draft' : 'try_nearest_available_window', 'label' => $hasDraftToKeep ? 'Continue with that plan' : $nearestActionLabel],
                ['id' => 'pick_another_time_window', 'label' => 'Try another time window'],
            ];
        } elseif (in_array('unplaced_units', $triggers, true)) {
            $reasonCode = 'unplaced_explicit_targets';
            $reasonMessage = 'At least one requested item could not be placed in the available time window.';
            $prompt = $hasDraftToKeep
                ? 'Should I continue with that plan, or pick another time this week?'
                : 'Should I schedule for tomorrow morning instead, or pick another time this week?';
            $options = $defaultOptions;
            $optionActions = [
                ['id' => $hasDraftToKeep ? 'use_current_draft' : 'try_nearest_available_window', 'label' => $hasDraftToKeep ? 'Continue with that plan' : $nearestActionLabel],
                ['id' => 'pick_another_time_window', 'label' => 'Try another time window'],
            ];
        } elseif (in_array('strict_window_no_fit', $triggers, true)) {
            $reasonCode = 'strict_window_no_fit';
            $reasonMessage = "Nothing fit inside {$requestedWindowLabel} with the strict time limits you set.";
            $prompt = $hasNearestWindow
                ? "I can try {$nearestPromptLabel}, or adjust your window. Which one should I do?"
                : 'I can try the closest available window, or adjust your window. Which one should I do?';
            $options = $defaultOptions;
            $optionActions = [
                ['id' => 'try_nearest_available_window', 'label' => $nearestActionLabel],
                ['id' => 'pick_another_time_window', 'label' => 'Try another time window'],
            ];
        } elseif ($plan->timeWindowHint === 'later') {
            $prompt = $hasDraftToKeep
                ? "I could not fit everything later today, but I can place these on {$datePhrase}. Would you like me to use this plan?"
                : ($hasNearestWindow
                    ? "There is no open slot left later today. Do you want me to try {$nearestPromptLabel} or another time window?"
                    : 'There is no open slot left later today. Do you want me to try the closest available window or another time window?');
            $reasonMessage = 'There is not enough free time left in your requested "later today" window.';
            $reasonCode = 'later_window_not_feasible';
            $options = $hasDraftToKeep
                ? ['Continue with that plan', 'Try another time window']
                : [$nearestActionLabel, 'Try another time window'];
            $optionActions = [
                ['id' => 'try_nearest_available_window', 'label' => $nearestActionLabel],
                ['id' => 'pick_another_time_window', 'label' => 'Try another time window'],
            ];
        } else {
            $reasonCode = 'schedule_confirmation_needed';
            $reasonMessage = 'I prepared a draft and need your confirmation before finalizing.';
            $prompt = 'Do you want to continue with that plan, or pick another time this week?';
            $options = $defaultOptions;
            $optionActions = [
                ['id' => $hasDraftToKeep ? 'use_current_draft' : 'try_nearest_available_window', 'label' => $hasDraftToKeep ? 'Continue with that plan' : $nearestActionLabel],
                ['id' => 'pick_another_time_window', 'label' => 'Try another time window'],
            ];
        }

        $scheduleData['confirmation_required'] = true;
        $scheduleData['awaiting_user_decision'] = true;
        $scheduleData['confirmation_context'] = [
            'reason_code' => $reasonCode,
            'requested_count' => $requestedCount,
            'placed_count' => $proposalsCount,
            'requested_count_source' => $requestedCountSource,
            'reason_message' => $reasonMessage,
            'requested_window' => $requestedWindow,
            'requested_window_display_label' => $requestedWindowLabel,
            'requested_horizon_label' => (string) ($requestedWindow['horizon_label'] ?? 'your requested window'),
            'nearest_available_window' => $nearestAvailableWindow,
            'attempted_horizon' => $attemptedHorizon,
            'fallback_horizon' => $fallbackHorizon,
            'reason_details' => $reasonDetails,
            'prompt' => $prompt,
            'options' => $options,
            'option_actions' => $optionActions,
            'approved_narrative' => [
                'framing' => (string) ($scheduleData['framing'] ?? ''),
                'reasoning' => (string) ($scheduleData['reasoning'] ?? ''),
                'confirmation' => (string) ($scheduleData['confirmation'] ?? ''),
            ],
        ];
        $scheduleData['fallback_preview'] = [
            'proposals_count' => is_array($scheduleData['proposals'] ?? null) ? count($scheduleData['proposals']) : 0,
            'days_used' => $daysUsed,
            'placement_dates' => $placementDates,
            'summary' => (string) ($digest['summary'] ?? ''),
            'reason_details' => $reasonDetails,
            'nearest_available_window' => $nearestAvailableWindow,
        ];
        $narrative = $this->deterministicScheduleExplanationService->composeConfirmation([
            'reason_code' => $reasonCode,
            'requested_count' => $requestedCount,
            'placed_count' => $proposalsCount,
            'requested_window_label' => $requestedWindowLabel,
            'reason_message' => $reasonMessage,
            'prompt' => $prompt,
            'reason_details' => $reasonDetails,
        ]);
        $scheduleData['framing'] = $narrative['framing'];
        $scheduleData['reasoning'] = $narrative['reasoning'];
        $scheduleData['confirmation'] = $narrative['confirmation'];
        $scheduleData['explanation_meta'] = is_array($narrative['explanation_meta'] ?? null) ? $narrative['explanation_meta'] : [];
        $scheduleData['confirmation_context']['reason_message'] = $narrative['reason_message'];
        $scheduleData['reasoning'] = $this->compactFallbackReasoning((string) $scheduleData['reasoning']);
        $scheduleData['confirmation_context']['reason_message'] = $this->compactFallbackReasoning(
            (string) $scheduleData['confirmation_context']['reason_message']
        );

        $digest['fallback_trigger_reason'] = (string) ($digest['fallback_trigger_reason'] ?? 'horizon_exhausted');
        $scheduleData['placement_digest'] = $digest;

        Log::info('task-assistant.schedule.confirmation_required', [
            'layer' => 'schedule_confirmation',
            'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
            'thread_id' => $thread->id,
            'user_id' => $thread->user_id,
            'user_message_preview' => $this->previewForLogs($userMessageContent),
            'placement_dates' => $placementDates,
        ]);

        return $scheduleData;
    }

    private function compactFallbackReasoning(string $text): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($value === '') {
            return '';
        }

        $value = (string) preg_replace('/\b(up to|within)\s+\d+\s+hours?\b[^.?!]*[.?!]?/iu', '', $value);
        $value = (string) preg_replace('/\bbetween\s+\d{1,2}\s*(?:am|pm)\s+and\s+\d{1,2}\s*(?:am|pm)\b/iu', 'in your requested window', $value);
        $value = (string) preg_replace('/\b\d{1,2}-hour\b/iu', 'requested', $value);
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ($value === '') {
            return '';
        }

        $parts = preg_split('/(?<=[.?!])\s+/u', $value) ?: [];
        $parts = array_values(array_filter(array_map(
            static fn (string $part): string => trim($part),
            $parts
        ), static fn (string $part): bool => $part !== ''));
        if ($parts === []) {
            return $value;
        }

        return implode(' ', array_slice($parts, 0, 2));
    }

    /**
     * @param  array<string, mixed>|null  $nearestAvailableWindow
     */
    private function buildNearestWindowDaypartLabel(?array $nearestAvailableWindow): string
    {
        if (! is_array($nearestAvailableWindow)) {
            return '';
        }

        $dateLabel = trim((string) ($nearestAvailableWindow['chip_label'] ?? ''));
        if ($dateLabel === '') {
            $dateLabel = trim((string) ($nearestAvailableWindow['date_label'] ?? ''));
        }

        $daypart = $this->resolveNearestWindowDaypartFromStartTime($nearestAvailableWindow);
        if ($dateLabel === '') {
            return $daypart;
        }
        if ($daypart === '') {
            return $dateLabel;
        }

        return "{$dateLabel} {$daypart}";
    }

    /**
     * @param  array<string, mixed>  $nearestAvailableWindow
     */
    private function resolveNearestWindowDaypartFromStartTime(array $nearestAvailableWindow): string
    {
        $startRaw = trim((string) ($nearestAvailableWindow['start_time'] ?? ''));
        if ($startRaw !== '') {
            $parts = explode(':', $startRaw);
            $hour = isset($parts[0]) ? (int) $parts[0] : null;
            if ($hour !== null && $hour >= 0 && $hour < 24) {
                return match (true) {
                    $hour < 12 => 'morning',
                    $hour < 18 => 'afternoon',
                    default => 'evening',
                };
            }
        }

        $fallbackDaypart = trim((string) ($nearestAvailableWindow['daypart'] ?? ''));

        return in_array($fallbackDaypart, ['morning', 'afternoon', 'evening'], true)
            ? $fallbackDaypart
            : '';
    }

    /**
     * @param  list<string>  $options
     * @param  list<string>  $options
     * @param  list<string>  $reasonDetails
     * @return array{framing: string, reasoning: string, confirmation: string, reason_message: string}
     */
    private function generateFallbackConfirmationNarrative(
        TaskAssistantThread $thread,
        string $userMessageContent,
        string $reasonCode,
        int $requestedCount,
        int $proposalsCount,
        string $requestedCountSource,
        string $requestedWindowLabel,
        ?string $strictDate,
        string $reasonMessage,
        string $prompt,
        array $options,
        array $reasonDetails,
        ?array $scheduleData = null,
    ): array {
        $fallback = $this->deterministicFallbackConfirmationNarrative(
            reasonCode: $reasonCode,
            requestedCount: $requestedCount,
            proposalsCount: $proposalsCount,
            requestedCountSource: $requestedCountSource,
            requestedWindowLabel: $requestedWindowLabel,
            strictDate: $strictDate,
            reasonMessage: $reasonMessage,
            prompt: $prompt,
        );

        Log::debug('task-assistant.schedule.confirmation_narrative_deterministic', [
            'layer' => 'schedule_confirmation',
            'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
            'thread_id' => $thread->id,
            'reason_code' => $reasonCode,
        ]);

        return $fallback;
    }

    /**
     * @return array{framing: string, reasoning: string, confirmation: string, reason_message: string}
     */
    private function deterministicFallbackConfirmationNarrative(
        string $reasonCode,
        int $requestedCount,
        int $proposalsCount,
        string $requestedCountSource,
        string $requestedWindowLabel,
        ?string $strictDate,
        string $reasonMessage,
        string $prompt,
    ): array {
        if ($reasonCode === 'top_n_shortfall') {
            $taskNoun = $proposalsCount === 1 ? 'task' : 'tasks';
            $framing = 'I drafted a plan and paused so you can choose how to continue.';
            if ($requestedCountSource === 'explicit_user') {
                $framing = "I kept your top {$requestedCount} request and built the best-fitting draft I could.";
            }

            return [
                'framing' => $framing,
                'reasoning' => "Only {$proposalsCount} {$taskNoun} fit in {$requestedWindowLabel}. Nothing is final yet, and we can adjust it together.",
                'confirmation' => $prompt,
                'reason_message' => $reasonMessage,
            ];
        }

        if ($reasonCode === 'explicit_day_not_feasible') {
            $datePhrase = $strictDate !== null
                ? CarbonImmutable::parse($strictDate)->format('M j, Y')
                : 'that day';

            return [
                'framing' => "I kept your {$datePhrase} request and paused before widening beyond that day.",
                'reasoning' => 'Nothing is final yet. Tell me if you want to keep this day or open a wider window.',
                'confirmation' => $prompt,
                'reason_message' => $reasonMessage,
            ];
        }

        if (in_array($reasonCode, [
            'empty_placement_no_fit',
            'strict_window_no_fit',
            'adaptive_relaxed_placement',
            'alternative_outside_requested_window',
            'placement_outside_horizon',
            'unplaced_explicit_targets',
            'schedule_confirmation_needed',
            'later_window_not_feasible',
        ], true)) {
            $framing = $proposalsCount > 0
                ? 'I prepared a draft and paused so you can review it before I finalize anything.'
                : 'I could not fit this in your current time window.';

            return [
                'framing' => $framing,
                'reasoning' => $reasonMessage,
                'confirmation' => $prompt,
                'reason_message' => $reasonMessage,
            ];
        }

        return [
            'framing' => 'I checked your request and prepared a backup draft so you still have a workable path forward.',
            'reasoning' => 'Nothing is final yet. I will only continue once you confirm.',
            'confirmation' => $prompt,
            'reason_message' => $reasonMessage,
        ];
    }

    private function containsRoboticFallbackPhrase(string $text): bool
    {
        $content = mb_strtolower($text);
        $bannedFragments = [
            'confidence:',
            'open time slots were available',
            'request was made explicitly by the user',
            'horizon dates',
            'placement digest',
        ];

        foreach ($bannedFragments as $fragment) {
            if (str_contains($content, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     * @return list<array{title: string, start_datetime: string, end_datetime: string}>
     */
    private function compactDraftPlacementsForConfirmation(array $proposals): array
    {
        $out = [];
        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            if (trim((string) ($proposal['title'] ?? '')) === 'No schedulable items found') {
                continue;
            }
            $out[] = [
                'title' => (string) ($proposal['title'] ?? ''),
                'start_datetime' => (string) ($proposal['start_datetime'] ?? ''),
                'end_datetime' => (string) ($proposal['end_datetime'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $scheduleData
     */
    private function detectRequestedCountSourceForConfirmation(string $userMessageContent, ?array $scheduleData = null): string
    {
        if (is_array($scheduleData)) {
            $digest = is_array($scheduleData['placement_digest'] ?? null) ? $scheduleData['placement_digest'] : [];
            $digestSource = (string) ($digest['requested_count_source'] ?? '');
            if (in_array($digestSource, ['explicit_user', 'system_default'], true)) {
                return $digestSource;
            }
        }

        $normalized = mb_strtolower(trim($userMessageContent));
        if ($normalized === '') {
            return 'system_default';
        }

        if (preg_match('/\b(top|first|next|only|limit)\s+(\d+|one|two|three|four|five|six|seven|eight|nine|ten|couple)\b/iu', $normalized) === 1) {
            return 'explicit_user';
        }

        if (preg_match('/\b(\d+|one|two|three|four|five|six|seven|eight|nine|ten|couple)\b\s+(tasks?|items?)\b/iu', $normalized) === 1) {
            return 'explicit_user';
        }

        if (preg_match('/\b(those|them)\s+(\d+|one|two|three|four|five|six|seven|eight|nine|ten|couple)\b/iu', $normalized) === 1) {
            return 'explicit_user';
        }

        return 'system_default';
    }

    /**
     * @return array<string, int|float>
     */
    private function resolveConfirmationClientOptions(): array
    {
        $temperature = config('task-assistant.generation.schedule_confirmation.temperature');
        $maxTokens = config('task-assistant.generation.schedule_confirmation.max_tokens');
        $topP = config('task-assistant.generation.schedule_confirmation.top_p');

        return [
            'timeout' => (int) config('prism.request_timeout', 120),
            'temperature' => is_numeric($temperature) ? (float) $temperature : 0.28,
            'max_tokens' => is_numeric($maxTokens) ? (int) $maxTokens : 420,
            'top_p' => is_numeric($topP) ? (float) $topP : 0.9,
        ];
    }

    private function isLatencyBudgetExceeded(): bool
    {
        $budgetMs = max(0, (int) config('task-assistant.performance.latency_budget_ms', 0));
        if ($budgetMs <= 0 || ! app()->bound('task_assistant.run_started_at_ms')) {
            return false;
        }

        $startedAtMs = (int) app('task_assistant.run_started_at_ms');
        $elapsedMs = (int) round(microtime(true) * 1000) - $startedAtMs;

        return $elapsedMs >= $budgetMs;
    }

    private function resolveProvider(): Provider
    {
        $provider = strtolower((string) config('task-assistant.provider', 'ollama'));

        return match ($provider) {
            'ollama' => Provider::Ollama,
            default => Provider::Ollama,
        };
    }

    private function resolveModel(): string
    {
        return (string) config('task-assistant.model', 'hermes3:3b');
    }

    /**
     * @param  array<string, mixed>  $scheduleData
     */
    private function strictRequestedDateFromScheduleData(array $scheduleData): ?string
    {
        $digest = is_array($scheduleData['placement_digest'] ?? null) ? $scheduleData['placement_digest'] : [];
        $strictRequested = (bool) ($digest['strict_day_requested'] ?? false);
        $strictDate = trim((string) ($digest['strict_day_date'] ?? ''));

        if (! $strictRequested || $strictDate === '') {
            return null;
        }

        return $strictDate;
    }

    /**
     * @param  array<string, mixed>  $scheduleData
     */
    private function requiresStrictDateFallbackConfirmation(array $scheduleData, string $strictDate): bool
    {
        $proposals = is_array($scheduleData['proposals'] ?? null) ? $scheduleData['proposals'] : [];
        if ($proposals === []) {
            return true;
        }

        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            $startDatetime = trim((string) ($proposal['start_datetime'] ?? ''));
            if ($startDatetime === '') {
                continue;
            }

            try {
                $proposalDate = CarbonImmutable::parse($startDatetime)->toDateString();
            } catch (\Throwable) {
                continue;
            }

            if ($proposalDate !== $strictDate) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $scheduleData
     * @return array{hint: string|null, label: string, horizon_label: string}
     */
    private function requestedWindowDescriptorFromScheduleData(array $scheduleData): array
    {
        $explicitLabel = trim((string) ($scheduleData['requested_window_display_label'] ?? ''));
        $horizonLabel = trim((string) ($scheduleData['requested_horizon_label'] ?? ''));
        if ($explicitLabel !== '') {
            return [
                'hint' => null,
                'label' => $explicitLabel,
                'horizon_label' => $horizonLabel !== '' ? $horizonLabel : 'your requested window',
            ];
        }

        $digest = is_array($scheduleData['placement_digest'] ?? null) ? $scheduleData['placement_digest'] : [];
        $fallbackHint = is_string($digest['time_window_hint'] ?? null) ? $digest['time_window_hint'] : null;
        $hint = $fallbackHint;

        if ($hint === null || trim($hint) === '') {
            return [
                'hint' => null,
                'label' => 'your requested window',
                'horizon_label' => $horizonLabel !== '' ? $horizonLabel : 'your requested window',
            ];
        }

        $normalized = str_replace('_', ' ', trim($hint));

        return [
            'hint' => $hint,
            'label' => $normalized !== '' ? $normalized : 'your requested window',
            'horizon_label' => $horizonLabel !== '' ? $horizonLabel : 'your requested window',
        ];
    }

    /**
     * @param  array<string, mixed>  $scheduleData
     * @return array<string, mixed>
     */
    private function attemptedHorizonDescriptorFromScheduleData(array $scheduleData): array
    {
        $digest = is_array($scheduleData['placement_digest'] ?? null) ? $scheduleData['placement_digest'] : [];
        $attempted = is_array($digest['attempted_horizon'] ?? null) ? $digest['attempted_horizon'] : [];
        if ($attempted !== []) {
            return $attempted;
        }

        $placementDates = array_values(array_filter(
            is_array($digest['placement_dates'] ?? null) ? $digest['placement_dates'] : [],
            static fn (mixed $value): bool => is_string($value) && trim($value) !== ''
        ));
        if (count($placementDates) > 1) {
            return [
                'mode' => 'range',
                'start_date' => $placementDates[0],
                'end_date' => $placementDates[array_key_last($placementDates)],
            ];
        }

        if ($placementDates !== []) {
            return [
                'mode' => 'single_day',
                'date' => $placementDates[0],
            ];
        }

        return [
            'mode' => 'single_day',
            'date' => CarbonImmutable::now((string) config('app.timezone', 'UTC'))->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $scheduleData
     * @return array<string, mixed>
     */
    private function fallbackHorizonDescriptorFromScheduleData(array $scheduleData): array
    {
        $digest = is_array($scheduleData['placement_digest'] ?? null) ? $scheduleData['placement_digest'] : [];
        $placementDates = array_values(array_filter(
            is_array($digest['placement_dates'] ?? null) ? $digest['placement_dates'] : [],
            static fn (mixed $value): bool => is_string($value) && trim($value) !== ''
        ));

        if (count($placementDates) > 1) {
            return [
                'mode' => 'range',
                'start_date' => $placementDates[0],
                'end_date' => $placementDates[array_key_last($placementDates)],
                'dates' => $placementDates,
            ];
        }

        if ($placementDates !== []) {
            return [
                'mode' => 'single_day',
                'date' => $placementDates[0],
                'dates' => $placementDates,
            ];
        }

        return [
            'mode' => 'single_day',
            'dates' => [],
        ];
    }

    /**
     * @param  array{schedule_data: array<string, mixed>, time_window_hint: string|null, initial_user_message: string, created_at?: string|null}  $pendingState
     */
    private function handlePendingScheduleFallbackDecision(
        TaskAssistantThread $thread,
        TaskAssistantMessage $userMessage,
        TaskAssistantMessage $assistantMessage,
        string $userMessageContent,
        array $pendingState,
        ?string $pendingActionId = null,
    ): bool {
        $actionId = $this->normalizeFallbackActionId($pendingActionId);
        if ($actionId !== null) {
            return $this->executePendingFallbackAction(
                thread: $thread,
                userMessage: $userMessage,
                assistantMessage: $assistantMessage,
                userMessageContent: $userMessageContent,
                pendingState: $pendingState,
                actionId: $actionId,
            );
        }

        $decision = $this->classifyScheduleFallbackDecision($userMessageContent);
        if ($decision === 'confirm') {
            return $this->finalizeApprovedPendingFallbackDraft($thread, $assistantMessage, $pendingState);
        }

        $pendingData = is_array($pendingState['schedule_data'] ?? null) ? $pendingState['schedule_data'] : [];
        $pendingProposals = is_array($pendingData['proposals'] ?? null) ? $pendingData['proposals'] : [];

        if ($decision === 'decline') {
            $this->conversationState->clearPendingScheduleFallback($thread);
            $this->publishScheduleClarificationResponse(
                thread: $thread,
                assistantMessage: $assistantMessage,
                proposals: $pendingProposals,
                clarification: 'No problem. Tell me the time window you prefer (for example: tomorrow morning, this week, or specific time).',
                examples: [
                    'try fitting all 3 tomorrow evening',
                    'schedule these this week morning',
                ],
            );

            return true;
        }

        $windowChangeDetected = $this->isLikelyScheduleWindowChangeRequest($userMessageContent);
        $replanDetected = $this->isLikelyScheduleReplanRequest($userMessageContent, $pendingState);
        $freshPrioritizeDetected = $this->isLikelyFreshPrioritizeRequest($userMessageContent);
        if ($windowChangeDetected || $replanDetected || $freshPrioritizeDetected) {
            // Treat natural "change/adjust the window" replies as a fresh scheduling
            // request instead of trapping the user in confirm/decline only handling.
            // Also release when the user clearly pivots to a fresh prioritize ask.
            // We clear pending state and let normal routing handle this message.
            $this->conversationState->clearPendingScheduleFallback($thread);

            Log::info('task-assistant.schedule.pending_released_for_replan', [
                'layer' => 'schedule_confirmation',
                'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
                'thread_id' => $thread->id,
                'assistant_message_id' => $assistantMessage->id,
                'message_preview' => $this->previewForLogs($userMessageContent),
                'pending_fallback_replan_detected' => $replanDetected,
                'pending_fallback_window_change_detected' => $windowChangeDetected,
                'pending_fallback_fresh_prioritize_detected' => $freshPrioritizeDetected,
                'pending_fallback_release_reason' => $freshPrioritizeDetected
                    ? 'fresh_prioritize_request'
                    : ($replanDetected ? 'replan_request' : 'window_change_request'),
            ]);

            return false;
        }

        $this->publishScheduleClarificationResponse(
            thread: $thread,
            assistantMessage: $assistantMessage,
            proposals: $pendingProposals,
            clarification: 'Please confirm first. Reply with yes/confirm to continue, or tell me another preferred window.',
            examples: [
                'yes, continue with this draft',
                'try fitting all 3 tomorrow evening',
            ],
        );

        return true;
    }

    /**
     * @param  array<string, mixed>  $pendingState
     */
    private function executePendingFallbackAction(
        TaskAssistantThread $thread,
        TaskAssistantMessage $userMessage,
        TaskAssistantMessage $assistantMessage,
        string $userMessageContent,
        array $pendingState,
        string $actionId,
    ): bool {
        if ($actionId === 'cancel_scheduling') {
            $this->conversationState->clearPendingScheduleFallback($thread);
            $this->publishScheduleClarificationResponse(
                thread: $thread,
                assistantMessage: $assistantMessage,
                proposals: [],
                clarification: 'Got it. I cancelled this scheduling draft. If you want, share a new time window and I can try again.',
                examples: [
                    'schedule tomorrow morning',
                    'schedule these this week',
                ],
            );

            return true;
        }

        if ($actionId === 'use_current_draft') {
            return $this->finalizeApprovedPendingFallbackDraft($thread, $assistantMessage, $pendingState);
        }

        if ($actionId === 'pick_another_time_window') {
            $pendingData = is_array($pendingState['schedule_data'] ?? null) ? $pendingState['schedule_data'] : [];
            $pendingContext = is_array($pendingData['confirmation_context'] ?? null) ? $pendingData['confirmation_context'] : [];
            $requestedCount = max(
                1,
                (int) ($pendingContext['requested_count'] ?? data_get($pendingData, 'placement_digest.requested_count', 3))
            );
            $targets = $this->targetEntitiesFromScheduleProposals(
                is_array($pendingData['proposals'] ?? null) ? $pendingData['proposals'] : []
            );
            $constraints = $this->routingPolicy->extractConstraintsForFlow(
                $thread,
                $userMessageContent,
                TaskAssistantFlowNames::PRIORITIZE_SCHEDULE
            );
            $constraints['count_limit'] = $requestedCount;
            $constraints['target_entities'] = $targets;
            if (! is_string($constraints['time_window_hint'] ?? null) || trim((string) $constraints['time_window_hint']) === '') {
                $constraints['time_window_hint'] = 'later';
            }

            $this->conversationState->clearPendingScheduleFallback($thread);
            $plan = new ExecutionPlan(
                flow: TaskAssistantFlowNames::PRIORITIZE_SCHEDULE,
                confidence: 1.0,
                clarificationNeeded: false,
                clarificationQuestion: null,
                reasonCodes: ['fallback_action_prioritize_schedule_later_this_week'],
                constraints: $constraints,
                targetEntities: $targets,
                timeWindowHint: is_string($constraints['time_window_hint'] ?? null) ? $constraints['time_window_hint'] : null,
                countLimit: max(1, min((int) ($constraints['count_limit'] ?? 3), 10)),
                generationProfile: 'schedule',
            );
            $this->persistRoutingTrace($assistantMessage, $plan, $plan);
            $this->logRoutingDecision($thread, $assistantMessage, $plan);
            $this->runPrioritizeScheduleFlow($thread, $userMessage, $assistantMessage, $userMessageContent, $plan);

            return true;
        }

        if ($actionId !== 'try_nearest_available_window') {
            return false;
        }

        $pendingData = is_array($pendingState['schedule_data'] ?? null) ? $pendingState['schedule_data'] : [];
        $pendingContext = is_array($pendingData['confirmation_context'] ?? null) ? $pendingData['confirmation_context'] : [];
        $requestedCount = max(
            1,
            (int) ($pendingContext['requested_count'] ?? data_get($pendingData, 'placement_digest.requested_count', 3))
        );
        $targets = $this->targetEntitiesFromScheduleProposals(
            is_array($pendingData['proposals'] ?? null) ? $pendingData['proposals'] : []
        );

        $constraints = $this->routingPolicy->extractConstraintsForFlow($thread, $userMessageContent, TaskAssistantFlowNames::SCHEDULE);
        $nearestWindow = is_array($pendingContext['nearest_available_window'] ?? null)
            ? $pendingContext['nearest_available_window']
            : null;
        $dynamicTimeHint = trim((string) ($nearestWindow['daypart'] ?? ''));
        $dynamicDate = trim((string) ($nearestWindow['date'] ?? ''));
        $constraints['time_window_hint'] = $dynamicTimeHint !== '' ? $dynamicTimeHint : 'morning';
        $constraints['count_limit'] = $requestedCount;
        $constraints['target_entities'] = $targets;
        if ($dynamicDate !== '') {
            $constraints['refinement_explicit_day_override'] = $dynamicDate;
            $constraints['refinement_anchor_date'] = $dynamicDate;
        }

        $this->conversationState->clearPendingScheduleFallback($thread);

        $plan = new ExecutionPlan(
            flow: TaskAssistantFlowNames::SCHEDULE,
            confidence: 1.0,
            clarificationNeeded: false,
            clarificationQuestion: null,
            reasonCodes: ['fallback_action_try_closest_available_window'],
            constraints: $constraints,
            targetEntities: $targets,
            timeWindowHint: $constraints['time_window_hint'],
            countLimit: $requestedCount,
            generationProfile: 'schedule',
        );
        $this->persistRoutingTrace($assistantMessage, $plan, $plan);
        $this->logRoutingDecision($thread, $assistantMessage, $plan);
        $this->runScheduleFlow($thread, $userMessage, $assistantMessage, $userMessageContent, $plan);

        return true;
    }

    private function normalizeFallbackActionId(?string $actionId): ?string
    {
        $normalized = trim((string) $actionId);

        return match ($normalized) {
            'try_tomorrow_morning' => 'try_nearest_available_window',
            'try_nearest_available_window',
            'use_current_draft',
            'pick_another_time_window',
            'cancel_scheduling' => $normalized,
            default => null,
        };
    }

    private function handleDeterministicChipClientAction(
        TaskAssistantThread $thread,
        TaskAssistantMessage $userMessage,
        TaskAssistantMessage $assistantMessage,
        int $assistantMessageId,
        string $content,
    ): bool {
        $actionId = $this->normalizeClientActionId($this->extractClientActionId($userMessage));
        if ($actionId === null) {
            return false;
        }

        // Fallback option actions are only valid while awaiting schedule confirmation.
        if (in_array($actionId, ['try_nearest_available_window', 'use_current_draft', 'pick_another_time_window', 'cancel_scheduling'], true)) {
            return false;
        }

        $flow = match ($actionId) {
            'chip_prioritize',
            'chip_prioritize_top_one',
            'chip_prioritize_top_three' => TaskAssistantFlowNames::PRIORITIZE,
            'chip_schedule' => TaskAssistantFlowNames::SCHEDULE,
            'chip_schedule_ranked_set',
            'chip_schedule_ranked_top_one' => TaskAssistantFlowNames::SCHEDULE,
            'chip_prioritize_schedule',
            'chip_prioritize_schedule_top_one' => TaskAssistantFlowNames::PRIORITIZE_SCHEDULE,
            default => null,
        };
        if ($flow === null) {
            return false;
        }

        $constraints = $this->routingPolicy->extractConstraintsForFlow($thread, $content, $flow);
        if (in_array($actionId, ['chip_schedule_ranked_set', 'chip_schedule_ranked_top_one'], true)) {
            $rankedTargets = $this->resolveRankedTargetsForScheduleChip(
                thread: $thread,
                topOneOnly: $actionId === 'chip_schedule_ranked_top_one',
            );
            if ($rankedTargets !== []) {
                $constraints['target_entities'] = $rankedTargets;
                $constraints['count_limit'] = $actionId === 'chip_schedule_ranked_top_one' ? 1 : count($rankedTargets);
                $constraints['count_limit_explicitly_requested'] = true;
                $constraints['is_strict_set_contract'] = true;
            }
        }
        $forcedCountLimit = match ($actionId) {
            'chip_prioritize_top_one',
            'chip_prioritize_schedule_top_one',
            'chip_schedule_ranked_top_one' => 1,
            'chip_prioritize_top_three' => 3,
            default => null,
        };
        if ($forcedCountLimit !== null) {
            $constraints['count_limit'] = $forcedCountLimit;
        }
        $countLimit = max(1, min((int) ($constraints['count_limit'] ?? 3), 10));
        $timeWindowHint = is_string($constraints['time_window_hint'] ?? null)
            ? $constraints['time_window_hint']
            : null;
        $targetEntities = is_array($constraints['target_entities'] ?? null)
            ? $constraints['target_entities']
            : [];

        $initialPlan = new ExecutionPlan(
            flow: $flow,
            confidence: 1.0,
            clarificationNeeded: false,
            clarificationQuestion: null,
            reasonCodes: ['client_action_'.$actionId],
            constraints: $constraints,
            targetEntities: $targetEntities,
            timeWindowHint: $timeWindowHint,
            countLimit: $countLimit,
            generationProfile: $flow === TaskAssistantFlowNames::PRIORITIZE_SCHEDULE ? 'schedule' : $flow,
        );
        $plan = $this->maybeRemapScheduleToPrioritize($thread, $initialPlan, $content);
        $plan = $this->maybeRewritePlanForScheduleRefinement($thread, $plan, $assistantMessage->id, $content);
        $this->persistRoutingTrace($assistantMessage, $initialPlan, $plan);
        $this->logRoutingDecision($thread, $assistantMessage, $plan);

        $candidateSnapshot = $this->candidateProvider->candidatesForUser(
            $thread->user,
            taskLimit: $this->snapshotTaskLimit(),
        );
        if ($this->isWorkspaceCandidateSnapshotEmpty($candidateSnapshot)) {
            $this->logWorkspaceEmptyShortcircuit($thread, $assistantMessageId, $plan->flow);
            $this->runEmptyWorkspaceFlow($thread, $assistantMessage, $content, $plan);

            return true;
        }

        $this->dispatchFlowHandler(new TaskAssistantFlowHandlerContext(
            thread: $thread,
            userMessage: $userMessage,
            assistantMessage: $assistantMessage,
            content: $content,
            plan: $plan,
        ));

        return true;
    }

    /**
     * @return array<int, array{entity_type: string, entity_id: int, title: string}>
     */
    private function resolveRankedTargetsForScheduleChip(TaskAssistantThread $thread, bool $topOneOnly): array
    {
        $listing = $this->conversationState->lastListing($thread);
        if (! is_array($listing)) {
            return [];
        }

        $items = is_array($listing['items'] ?? null) ? $listing['items'] : [];
        $targets = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $entityType = trim((string) ($item['entity_type'] ?? ''));
            $entityId = (int) ($item['entity_id'] ?? 0);
            if ($entityType === '' || $entityId <= 0) {
                continue;
            }
            $targets[] = [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'title' => trim((string) ($item['title'] ?? '')),
            ];
            if ($topOneOnly) {
                break;
            }
        }

        return $targets;
    }

    private function normalizeClientActionId(?string $actionId): ?string
    {
        $normalized = trim((string) $actionId);
        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'try_tomorrow_morning' => 'try_nearest_available_window',
            'try_nearest_available_window',
            'use_current_draft',
            'pick_another_time_window',
            'cancel_scheduling',
            'chip_prioritize',
            'chip_prioritize_top_one',
            'chip_prioritize_top_three',
            'chip_schedule',
            'chip_schedule_ranked_set',
            'chip_schedule_ranked_top_one',
            'chip_prioritize_schedule',
            'chip_prioritize_schedule_top_one' => $normalized,
            default => null,
        };
    }

    /**
     * @param  array{schedule_data?: array<string, mixed>, time_window_hint?: string|null}  $pendingState
     */
    private function finalizeApprovedPendingFallbackDraft(
        TaskAssistantThread $thread,
        TaskAssistantMessage $assistantMessage,
        array $pendingState,
    ): bool {
        $data = is_array($pendingState['schedule_data'] ?? null) ? $pendingState['schedule_data'] : [];
        if ($data === []) {
            $this->conversationState->clearPendingScheduleFallback($thread);

            return false;
        }

        $ctx = is_array($data['confirmation_context'] ?? null) ? $data['confirmation_context'] : [];
        $approvedNarrative = is_array($ctx['approved_narrative'] ?? null) ? $ctx['approved_narrative'] : [];
        $data['confirmation_required'] = false;
        $data['awaiting_user_decision'] = false;
        $data['confirmation_context'] = null;
        $data['fallback_preview'] = null;
        $approvedFraming = trim((string) ($approvedNarrative['framing'] ?? ''));
        $approvedReasoning = trim((string) ($approvedNarrative['reasoning'] ?? ''));
        $approvedConfirmation = trim((string) ($approvedNarrative['confirmation'] ?? ''));
        if ($approvedFraming !== '') {
            $data['framing'] = $approvedFraming;
        }
        if ($approvedReasoning !== '') {
            $data['reasoning'] = $approvedReasoning;
        }
        if ($approvedConfirmation !== '') {
            $data['confirmation'] = $approvedConfirmation;
        }

        $generationResult = [
            'valid' => true,
            'data' => $data,
            'errors' => [],
        ];
        $execution = $this->flowExecutionEngine->executeStructuredFlow(
            flow: 'daily_schedule',
            metadataKey: 'schedule',
            thread: $thread,
            assistantMessage: $assistantMessage,
            generationResult: $generationResult,
            assistantFallbackContent: 'I had trouble finalizing that schedule. Please try again.',
        );

        $proposals = is_array($data['proposals'] ?? null) ? $data['proposals'] : [];
        $targets = $this->targetEntitiesFromScheduleProposals($proposals);
        $referencedProposalUuids = array_values(array_filter(array_map(
            static fn (mixed $proposal): string => is_array($proposal)
                ? trim((string) ($proposal['proposal_uuid'] ?? $proposal['proposal_id'] ?? ''))
                : '',
            $proposals
        ), static fn (string $uuid): bool => $uuid !== ''));
        $this->conversationState->rememberScheduleContext(
            $thread,
            $targets,
            is_string($pendingState['time_window_hint'] ?? null) ? $pendingState['time_window_hint'] : null,
            $referencedProposalUuids,
            $assistantMessage->id,
        );
        $this->conversationState->clearPendingScheduleFallback($thread);

        $this->streamFlowEnvelope(
            thread: $thread,
            assistantMessage: $assistantMessage,
            flow: 'schedule',
            execution: $execution
        );

        return true;
    }

    private function extractClientActionId(TaskAssistantMessage $userMessage): ?string
    {
        $metadata = is_array($userMessage->metadata ?? null) ? $userMessage->metadata : [];
        $actionId = trim((string) data_get($metadata, 'client_action.id', ''));

        return $actionId !== '' ? $actionId : null;
    }

    private function isLikelyScheduleWindowChangeRequest(string $content): bool
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $content) ?? $content));
        if ($normalized === '') {
            return false;
        }

        $hasWindowVerb = preg_match('/\b(adjust|change|move|switch|update|widen|expand|shift|use|pick|set|fit|fitting|include|accommodate|retry|replan)\b/u', $normalized) === 1;
        $hasWindowNoun = preg_match('/\b(window|time|slot|schedule|whole day|all day|tomorrow|today|this week|next week|morning|afternoon|evening|night|later)\b/u', $normalized) === 1;
        $hasScheduleAction = preg_match('/\b(schedule|plan|reschedule|top\s+\d+|top\s+tasks?|fit\s+all|all\s+of\s+them)\b/u', $normalized) === 1;
        $hasClockAnchor = preg_match('/\b\d{1,2}(:\d{2})?\s*(am|pm)\b/u', $normalized) === 1;

        return ($hasWindowVerb && $hasWindowNoun)
            || ($hasScheduleAction && $hasWindowNoun)
            || ($hasWindowVerb && $hasClockAnchor)
            || ($hasScheduleAction && $hasClockAnchor);
    }

    /**
     * Detect natural-language requests that mean "rerun scheduling with broader constraints"
     * while we are waiting on fallback confirmation.
     *
     * @param  array{schedule_data?: array<string, mixed>}  $pendingState
     */
    private function isLikelyScheduleReplanRequest(string $content, array $pendingState = []): bool
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $content) ?? $content));
        if ($normalized === '') {
            return false;
        }

        $hasReplanVerb = preg_match('/\b(try|retry|replan|fit|fitting|include|accommodate|adjust|widen|expand)\b/u', $normalized) === 1;
        $hasPluralTarget = preg_match('/\b(all|all of them|everything|both|all tasks|all items)\b/u', $normalized) === 1;
        $hasCountPhrase = preg_match('/\b(all\s+\d+|top\s+\d+|fit\s+all\s+\d+)\b/u', $normalized) === 1;
        $hasWindowCue = preg_match('/\b(later|today|tomorrow|this week|next week|morning|afternoon|evening|night|whole day|all day)\b/u', $normalized) === 1;

        $pendingData = is_array($pendingState['schedule_data'] ?? null) ? $pendingState['schedule_data'] : [];
        $pendingContext = is_array($pendingData['confirmation_context'] ?? null) ? $pendingData['confirmation_context'] : [];
        $pendingRequestedCount = max(
            0,
            (int) ($pendingContext['requested_count'] ?? data_get($pendingData, 'placement_digest.requested_count', 0))
        );
        $mentionsPendingCount = $pendingRequestedCount > 0
            && preg_match('/\b(?:all|fit|include|top)?\s*'.preg_quote((string) $pendingRequestedCount, '/').'\b/u', $normalized) === 1;

        return ($hasReplanVerb && ($hasPluralTarget || $hasCountPhrase || $hasWindowCue || $mentionsPendingCount))
            || ($hasPluralTarget && $hasWindowCue)
            || ($mentionsPendingCount && $hasReplanVerb);
    }

    private function isLikelyFreshPrioritizeRequest(string $content): bool
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $content) ?? $content));
        if ($normalized === '') {
            return false;
        }

        // Preserve explicit schedule-window edits and replan requests in their dedicated branches.
        if ($this->isLikelyScheduleWindowChangeRequest($normalized) || $this->isLikelyScheduleReplanRequest($normalized)) {
            return false;
        }

        $hasPrioritizeCue = preg_match(
            '/\b(priorit(?:y|ize)|rank|ranking|what should i do first|which should i do first|top\s+\d+|top\s+tasks?|most urgent|most important|first task)\b/u',
            $normalized
        ) === 1;
        if (! $hasPrioritizeCue) {
            return false;
        }

        $hasFreshIntentCue = preg_match('/\b(new|instead|forget|skip|different|now|right now)\b/u', $normalized) === 1
            || preg_match('/\b(what|which)\b.{0,30}\b(first|next)\b/u', $normalized) === 1;
        $hasTaskDomainCue = preg_match('/\b(task|tasks|item|items|priority|priorities)\b/u', $normalized) === 1;

        return $hasTaskDomainCue || $hasFreshIntentCue;
    }

    private function classifyScheduleFallbackDecision(string $content): string
    {
        return match ($this->scheduleFallbackPolicy->classifyPendingDecision($content)) {
            'confirm' => 'confirm',
            'decline' => 'decline',
            default => 'unclear',
        };
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
            'prioritize_schedule',
            'listing_followup',
            'general_guidance' => $decision->flow,
            default => throw new \UnexpectedValueException('Unsupported routing flow: '.$decision->flow),
        };
        $countLimit = max(1, min((int) ($constraints['count_limit'] ?? 3), 10));
        $timeWindowHint = is_string($constraints['time_window_hint'] ?? null) ? $constraints['time_window_hint'] : null;
        $targetEntities = is_array($constraints['target_entities'] ?? null) ? $constraints['target_entities'] : [];

        $generationProfile = match ($flow) {
            'schedule' => 'schedule',
            'prioritize' => 'prioritize',
            'prioritize_schedule' => 'schedule',
            'listing_followup' => 'listing_followup',
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

    private function runNamedTaskClarificationFlow(
        TaskAssistantThread $thread,
        TaskAssistantMessage $assistantMessage,
        string $userMessageContent,
        ExecutionPlan $plan,
    ): void {
        $question = trim((string) $plan->clarificationQuestion);
        if ($question === '') {
            $question = 'I found multiple matching tasks. Which one should I schedule?';
        }

        $namedResolution = is_array($plan->constraints['named_task_resolution'] ?? null)
            ? $plan->constraints['named_task_resolution']
            : [];
        $candidateTitles = is_array($namedResolution['candidates'] ?? null)
            ? array_values(array_filter(array_map(
                static fn (mixed $candidate): string => is_array($candidate)
                    ? trim((string) ($candidate['title'] ?? ''))
                    : '',
                $namedResolution['candidates']
            ), static fn (string $title): bool => $title !== ''))
            : [];

        $candidateTitles = array_slice($candidateTitles, 0, 3);
        $numberedChoices = [];
        foreach ($candidateTitles as $index => $title) {
            $numberedChoices[] = ($index + 1).') '.$title;
        }
        $numberedText = $numberedChoices !== [] ? "\n\n".implode("\n", $numberedChoices) : '';

        $generationResult = [
            'valid' => true,
            'data' => [
                'intent' => 'task',
                'acknowledgement' => 'I can schedule that for you.',
                'message' => $question.$numberedText,
                'suggested_next_actions' => [
                    'Reply with a number (for example 1 or 2) or the exact title.',
                ],
                'next_options' => 'Reply with the number of your task choice and I will schedule it in your requested time window.',
                'next_options_chip_texts' => [],
            ],
            'errors' => [],
        ];

        $candidates = is_array($namedResolution['candidates'] ?? null)
            ? array_values(array_filter($namedResolution['candidates'], static fn (mixed $row): bool => is_array($row)))
            : [];
        if ($candidates !== []) {
            $this->conversationState->rememberPendingNamedTaskClarification(
                thread: $thread,
                initialUserMessage: $userMessageContent,
                question: $question,
                flow: $plan->flow,
                candidates: $candidates,
            );
        }

        $execution = $this->flowExecutionEngine->executeStructuredFlow(
            flow: 'general_guidance',
            metadataKey: 'general_guidance',
            thread: $thread,
            assistantMessage: $assistantMessage,
            generationResult: $generationResult,
            assistantFallbackContent: $question,
        );

        $this->streamFlowEnvelope(
            thread: $thread,
            assistantMessage: $assistantMessage,
            flow: 'general_guidance',
            execution: $execution
        );
    }

    /**
     * @param  array{
     *   initial_user_message: string,
     *   question: string,
     *   flow: string,
     *   candidates: list<array{entity_type: string, entity_id: int, title: string}>,
     *   created_at?: string
     * }  $pendingState
     */
    private function handlePendingNamedTaskClarification(
        TaskAssistantThread $thread,
        TaskAssistantMessage $userMessage,
        TaskAssistantMessage $assistantMessage,
        string $userMessageContent,
        array $pendingState,
    ): bool {
        $selected = $this->resolvePendingNamedTaskSelection($userMessageContent, $pendingState['candidates']);
        if ($selected === null) {
            if (preg_match('/^\s*\d+\s*$/u', $userMessageContent) === 1) {
                $this->publishNamedTaskChoiceReminder($thread, $assistantMessage, $pendingState);

                return true;
            }

            $this->conversationState->clearPendingNamedTaskClarification($thread);

            return false;
        }

        $this->conversationState->clearPendingNamedTaskClarification($thread);

        $composedMessage = trim((string) ($pendingState['initial_user_message'] ?? ''));
        if ($composedMessage === '') {
            $composedMessage = $userMessageContent;
        }

        $flow = in_array((string) ($pendingState['flow'] ?? ''), ['schedule', 'prioritize_schedule'], true)
            ? (string) $pendingState['flow']
            : 'schedule';
        $forcedConstraints = $this->routingPolicy->extractConstraintsForFlow($thread, $composedMessage, $flow);
        $forcedConstraints['target_entities'] = [$selected];
        $forcedConstraints['named_task_resolution'] = [
            'status' => 'single',
            'matched_phrase' => null,
            'target_entity' => $selected,
            'clarification_question' => null,
            'candidates' => [$selected],
        ];

        $forcedTimeWindowHint = is_string($forcedConstraints['time_window_hint'] ?? null)
            ? $forcedConstraints['time_window_hint']
            : null;
        $forcedPlan = new ExecutionPlan(
            flow: $flow,
            confidence: 1.0,
            clarificationNeeded: false,
            clarificationQuestion: null,
            reasonCodes: ['named_task_clarification_selection'],
            constraints: $forcedConstraints,
            targetEntities: [$selected],
            timeWindowHint: $forcedTimeWindowHint,
            countLimit: 1,
            generationProfile: 'schedule',
        );

        $this->logRoutingDecision($thread, $assistantMessage, $forcedPlan);

        if ($forcedPlan->flow === 'prioritize_schedule') {
            $this->runPrioritizeScheduleFlow($thread, $userMessage, $assistantMessage, $composedMessage, $forcedPlan);

            return true;
        }

        $this->runScheduleFlow($thread, $userMessage, $assistantMessage, $composedMessage, $forcedPlan);

        return true;
    }

    /**
     * @param  list<array{entity_type: string, entity_id: int, title: string}>  $candidates
     * @return array{entity_type: string, entity_id: int, title: string}|null
     */
    private function resolvePendingNamedTaskSelection(string $content, array $candidates): ?array
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^\s*(\d+)\s*$/u', $trimmed, $matches) === 1) {
            $index = ((int) ($matches[1] ?? 0)) - 1;
            if ($index >= 0 && isset($candidates[$index])) {
                return $candidates[$index];
            }

            return null;
        }

        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed));
        foreach ($candidates as $candidate) {
            $title = mb_strtolower(trim((string) ($candidate['title'] ?? '')));
            if ($title !== '' && str_contains($normalized, $title)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array{
     *   question: string,
     *   candidates: list<array{entity_type: string, entity_id: int, title: string}>
     * }  $pendingState
     */
    private function publishNamedTaskChoiceReminder(
        TaskAssistantThread $thread,
        TaskAssistantMessage $assistantMessage,
        array $pendingState,
    ): void {
        $question = trim((string) ($pendingState['question'] ?? ''));
        if ($question === '') {
            $question = 'Please choose one task so I can schedule it.';
        }
        $titles = array_map(
            static fn (array $candidate): string => (string) ($candidate['title'] ?? ''),
            array_slice($pendingState['candidates'], 0, 3)
        );
        $numbered = [];
        foreach ($titles as $index => $title) {
            $title = trim($title);
            if ($title === '') {
                continue;
            }
            $numbered[] = ($index + 1).') '.$title;
        }

        $message = trim($question."\n\n".implode("\n", $numbered));
        $generationResult = [
            'valid' => true,
            'data' => [
                'intent' => 'task',
                'acknowledgement' => 'I still need your task choice first.',
                'message' => $message,
                'suggested_next_actions' => ['Reply with 1, 2, or 3 to pick the exact task.'],
                'next_options' => 'Once you pick a number, I will continue scheduling.',
                'next_options_chip_texts' => [],
            ],
            'errors' => [],
        ];

        $execution = $this->flowExecutionEngine->executeStructuredFlow(
            flow: 'general_guidance',
            metadataKey: 'general_guidance',
            thread: $thread,
            assistantMessage: $assistantMessage,
            generationResult: $generationResult,
            assistantFallbackContent: $message,
        );

        $this->streamFlowEnvelope(
            thread: $thread,
            assistantMessage: $assistantMessage,
            flow: 'general_guidance',
            execution: $execution
        );
    }

    private function runListingFollowupFlow(
        TaskAssistantThread $thread,
        TaskAssistantMessage $assistantMessage,
        string $content,
        ExecutionPlan $plan,
    ): void {
        $thread->refresh();

        Log::info('task-assistant.flow', [
            'layer' => 'flow',
            'flow' => 'listing_followup',
            'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessage->id,
            'target_entities_count' => count($plan->targetEntities),
        ]);

        $targets = $plan->targetEntities;
        if ($targets === []) {
            $generationResult = [
                'valid' => true,
                'data' => [
                    'verdict' => 'partial',
                    'compared_items' => [],
                    'more_urgent_alternatives' => [],
                    'framing' => 'I do not have a recent list or schedule in this chat yet to compare.',
                    'rationale' => 'Try asking for your top tasks or a schedule first, then ask again about whether those items are the most urgent.',
                    'caveats' => null,
                    'next_options' => 'If you want, I can show a prioritized list or help you block time next.',
                    'next_options_chip_texts' => [
                        'What should I do first',
                        'Plan my day tomorrow',
                    ],
                ],
                'errors' => [],
            ];
        } else {
            $generationResult = $this->listingFollowupService->generate(
                $thread->user,
                $thread,
                $content,
                $targets,
            );
            if (! ($generationResult['valid'] ?? false)) {
                $generationResult = [
                    'valid' => true,
                    'data' => [
                        'verdict' => 'partial',
                        'compared_items' => [],
                        'more_urgent_alternatives' => [],
                        'framing' => 'I could not line up those items with your workspace snapshot just now.',
                        'rationale' => 'Try a quick prioritize request so we have a fresh ordered slice to talk about.',
                        'caveats' => null,
                        'next_options' => 'If you want, I can list what to tackle first or sketch a simple schedule.',
                        'next_options_chip_texts' => [
                            'What should I do first',
                            'Plan my day tomorrow',
                        ],
                    ],
                    'errors' => [],
                ];
            }
        }

        $execution = $this->flowExecutionEngine->executeStructuredFlow(
            flow: 'listing_followup',
            metadataKey: 'listing_followup',
            thread: $thread,
            assistantMessage: $assistantMessage,
            generationResult: $generationResult,
            assistantFallbackContent: 'I had trouble answering that follow-up. Try asking for your top tasks again, then repeat the question.',
        );

        if (($execution['final_valid'] ?? false) === true) {
            $structured = $execution['structured_data'] ?? [];
            $compared = is_array($structured['compared_items'] ?? null) ? $structured['compared_items'] : [];
            if ($compared !== []) {
                $thread->refresh();
                $preserveScheduleDraft = $this->conversationState->shouldPreserveScheduleDraftForListingFollowup($thread);
                $this->conversationState->rememberListingFollowupContext(
                    $thread,
                    $compared,
                    $assistantMessage->id,
                    $preserveScheduleDraft,
                );
            }
        }

        $this->streamFlowEnvelope(
            thread: $thread,
            assistantMessage: $assistantMessage,
            flow: 'listing_followup',
            execution: $execution
        );
    }

    private function runPrioritizeScheduleFlow(
        TaskAssistantThread $thread,
        TaskAssistantMessage $userMessage,
        TaskAssistantMessage $assistantMessage,
        string $content,
        ExecutionPlan $plan,
    ): void {
        $thread->refresh();

        Log::info('task-assistant.flow', [
            'layer' => 'flow',
            'flow' => 'prioritize_schedule',
            'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessage->id,
            'target_entities_count' => count($plan->targetEntities),
            'time_window_hint' => $plan->timeWindowHint,
            'count_limit' => $plan->countLimit,
        ]);

        $historyMessages = collect($this->mapToPrismMessages($this->loadHistoryMessages($thread, $userMessage->id)));

        $explicitTaskTargets = array_values(array_filter($plan->targetEntities, static function (mixed $entity): bool {
            if (! is_array($entity)) {
                return false;
            }

            $type = (string) ($entity['entity_type'] ?? '');
            $id = (int) ($entity['entity_id'] ?? 0);

            return $type === 'task' && $id > 0;
        }));

        $topTaskEntities = [];
        $topTaskEntities = $this->structuredFlowGenerator->resolvePrioritizeScheduleTaskTargets(
            thread: $thread,
            userMessageContent: $content,
            explicitTaskTargets: $explicitTaskTargets,
            countLimit: $plan->countLimit,
        );

        $explicitRequestedCount = $this->extractExplicitRequestedCount($content);
        $snapshot = $this->candidateProvider->candidatesForUser(
            $thread->user,
            taskLimit: $this->snapshotTaskLimit(),
        );
        $todoTaskCount = $this->countTodoTasksFromSnapshot($snapshot);
        $doingMeta = $this->collectDoingTasksFromSnapshot($snapshot);
        if ($todoTaskCount === 0 && $doingMeta['count'] > 0) {
            $result = $this->buildDoingOnlyScheduleGenerationResult(
                $doingMeta['titles'],
                $plan->timeWindowHint,
                $plan->countLimit,
            );
        } else {
            $result = $this->structuredFlowGenerator->generateDailySchedule(
                thread: $thread,
                userMessageContent: $content,
                historyMessages: $historyMessages,
                options: [
                    'target_entities' => $topTaskEntities,
                    'scheduling_scope' => 'tasks_only',
                    'schedule_source' => 'prioritize_schedule',
                    'time_window_hint' => $plan->timeWindowHint,
                    'count_limit' => $plan->countLimit,
                    'explicit_requested_count' => $explicitRequestedCount,
                    'is_strict_set_contract' => (bool) ($plan->constraints['is_strict_set_contract'] ?? false),
                    'schedule_user_id' => $thread->user_id,
                ]
            );
        }

        $result = $this->maybeConvertToScheduleFallbackConfirmation(
            thread: $thread,
            userMessageContent: $content,
            plan: $plan,
            generationResult: $result,
        );
        $result = $this->scheduleFallbackConfirmationService->finalize(
            generationData: $result,
            confirmationRequired: (bool) data_get($result, 'data.confirmation_required', false),
        )['data'];

        $execution = $this->flowExecutionEngine->executeStructuredFlow(
            flow: 'daily_schedule',
            metadataKey: 'schedule',
            thread: $thread,
            assistantMessage: $assistantMessage,
            generationResult: $result,
            assistantFallbackContent: 'I had trouble scheduling these items. Please try again with more details.'
        );

        $referencedProposalUuids = [];
        $genData = is_array($result['data'] ?? null) ? $result['data'] : [];
        $proposals = is_array($genData['proposals'] ?? null) ? $genData['proposals'] : [];
        $confirmationRequired = (bool) ($genData['confirmation_required'] ?? false);

        if ($confirmationRequired) {
            $this->conversationState->rememberPendingScheduleFallback(
                thread: $thread,
                scheduleData: $genData,
                timeWindowHint: $plan->timeWindowHint,
                initialUserMessage: $content,
            );
        } else {
            $this->conversationState->clearPendingScheduleFallback($thread);
        }

        // Store only schedulable proposal UUIDs so pronoun-based edits (it/this/that)
        // can resolve even for single-target schedules.
        $referencedProposalUuids = $this->scheduleProposalReferenceService->collectReferencedPendingSchedulableUuids($proposals);

        if (! $confirmationRequired) {
            $this->conversationState->rememberScheduleContext(
                $thread,
                $topTaskEntities,
                $plan->timeWindowHint,
                $referencedProposalUuids,
                $assistantMessage->id,
            );
        }

        $this->streamFlowEnvelope(
            thread: $thread,
            assistantMessage: $assistantMessage,
            flow: 'prioritize_schedule',
            execution: $execution
        );
    }

    private function logRoutingDecision(TaskAssistantThread $thread, TaskAssistantMessage $assistantMessage, ExecutionPlan $plan): void
    {
        $scheduleSignalStrength = data_get($plan->constraints, 'routing_signal_strength.schedule');
        $routingHint = data_get($plan->constraints, 'routing_hint');
        $demotionReasonDetail = data_get($plan->constraints, 'demotion_reason_detail');

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
            'schedule_signal_strength' => is_numeric($scheduleSignalStrength) ? (float) $scheduleSignalStrength : null,
            'routing_hint' => is_string($routingHint) ? $routingHint : null,
            'demotion_reason_detail' => is_string($demotionReasonDetail) ? $demotionReasonDetail : null,
            'prioritize_variant' => $plan->flow === 'prioritize' ? TaskAssistantPrioritizeVariant::Rank->value : null,
            'intent_use_llm' => false,
            ...$this->buildInferenceTelemetry($plan),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInferenceTelemetry(ExecutionPlan $plan): array
    {
        $provider = (string) config('task-assistant.provider', 'ollama');
        $model = (string) config('task-assistant.model', 'hermes3:3b');
        $providerUrl = $this->resolveProviderUrlForLogs($provider);

        return [
            'inference_mode' => $this->resolveInferenceModeForLogs($plan),
            'intent_inference_enabled' => false,
            'intent_inference_skipped' => true,
            'llm_provider' => $provider,
            'llm_model' => $model,
            'llm_endpoint_host' => $providerUrl !== null ? parse_url($providerUrl, PHP_URL_HOST) : null,
        ];
    }

    private function resolveInferenceModeForLogs(ExecutionPlan $plan): string
    {
        return 'deterministic_only';
    }

    private function resolveProviderUrlForLogs(string $provider): ?string
    {
        $providerUrl = config('prism.providers.'.$provider.'.url');

        return is_string($providerUrl) && $providerUrl !== '' ? $providerUrl : null;
    }

    private function persistRoutingTrace(
        TaskAssistantMessage $assistantMessage,
        ExecutionPlan $initialPlan,
        ExecutionPlan $finalPlan
    ): void {
        $metadata = is_array($assistantMessage->metadata ?? null) ? $assistantMessage->metadata : [];
        $rewrites = [];

        if ($initialPlan->flow !== $finalPlan->flow) {
            $rewrites[] = $initialPlan->flow.'->'.$finalPlan->flow;
        }
        if ($initialPlan->reasonCodes !== $finalPlan->reasonCodes) {
            $rewrites[] = 'reason_codes_changed';
        }

        data_set($metadata, 'routing_trace', [
            'initial_flow' => $initialPlan->flow,
            'final_flow' => $finalPlan->flow,
            'initial_reason_codes' => $initialPlan->reasonCodes,
            'final_reason_codes' => $finalPlan->reasonCodes,
            'rewrites' => $rewrites,
            'recorded_at' => now()->toIso8601String(),
        ]);

        $assistantMessage->update(['metadata' => $metadata]);
    }

    /**
     * @param  list<array<string, mixed>>  $proposals
     */
    private function hasSchoolClassConflictInDraft(TaskAssistantThread $thread, array $proposals, string $timezone): bool
    {
        $draftIntervals = [];
        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            if ((string) ($proposal['status'] ?? 'pending') !== 'pending') {
                continue;
            }
            if (trim((string) ($proposal['title'] ?? '')) === SchedulableProposalPolicy::NO_SCHEDULABLE_ITEMS_TITLE) {
                continue;
            }

            $startRaw = trim((string) ($proposal['start_datetime'] ?? ''));
            if ($startRaw === '') {
                continue;
            }

            try {
                $startAt = CarbonImmutable::parse($startRaw, $timezone);
            } catch (\Throwable) {
                continue;
            }

            $endRaw = trim((string) ($proposal['end_datetime'] ?? ''));
            if ($endRaw !== '') {
                try {
                    $endAt = CarbonImmutable::parse($endRaw, $timezone);
                } catch (\Throwable) {
                    continue;
                }
            } else {
                $minutes = max(1, (int) ($proposal['duration_minutes'] ?? 30));
                $endAt = $startAt->addMinutes($minutes);
            }

            if ($endAt->lessThanOrEqualTo($startAt)) {
                continue;
            }

            $draftIntervals[] = ['start' => $startAt, 'end' => $endAt];
        }

        if ($draftIntervals === []) {
            return false;
        }

        $minStart = collect($draftIntervals)->min('start');
        $maxEnd = collect($draftIntervals)->max('end');
        if (! $minStart instanceof CarbonImmutable || ! $maxEnd instanceof CarbonImmutable) {
            return false;
        }

        $classBusy = $this->schoolClassBusyIntervalResolver->resolveForUser(
            user: $thread->user,
            rangeStart: $minStart->copy()->startOfDay(),
            rangeEnd: $maxEnd->copy()->endOfDay(),
            bufferMinutes: max(0, (int) config('task-assistant.schedule.school_class_buffer_minutes', 15)),
            timezone: $timezone,
        );

        if ($classBusy === []) {
            return false;
        }

        foreach ($draftIntervals as $draft) {
            foreach ($classBusy as $busy) {
                if (! is_array($busy)) {
                    continue;
                }
                try {
                    $busyStart = CarbonImmutable::parse((string) ($busy['start'] ?? ''), $timezone);
                    $busyEnd = CarbonImmutable::parse((string) ($busy['end'] ?? ''), $timezone);
                } catch (\Throwable) {
                    continue;
                }
                if ($busyEnd->lessThanOrEqualTo($busyStart)) {
                    continue;
                }

                if ($draft['start']->lessThan($busyEnd) && $draft['end']->greaterThan($busyStart)) {
                    return true;
                }
            }
        }

        return false;
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
        return $this->processingGuard->isCancellationRequested($thread, $assistantMessageId);
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
