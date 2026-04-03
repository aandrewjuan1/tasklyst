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
use App\Services\LLM\Scheduling\PlacementDigestRebuilder;
use App\Services\LLM\Scheduling\ScheduleDraftMetadataNormalizer;
use App\Services\LLM\Scheduling\ScheduleDraftMutationService;
use App\Services\LLM\Scheduling\ScheduleEditLexicon;
use App\Services\LLM\Scheduling\ScheduleEditTargetResolver;
use App\Services\LLM\Scheduling\ScheduleEditTemporalParser;
use App\Services\LLM\Scheduling\ScheduleRefinementClauseSplitter;
use App\Services\LLM\Scheduling\ScheduleRefinementIntentResolver;
use App\Services\LLM\Scheduling\ScheduleRefinementPlacementRouter;
use App\Services\LLM\Scheduling\ScheduleRefinementStructuredOpExtractor;
use App\Services\LLM\Scheduling\TaskAssistantScheduleDbContextBuilder;
use App\Services\LLM\Scheduling\TaskAssistantStructuredFlowGenerator;
use App\Support\LLM\TaskAssistantPrioritizeOutputDefaults;
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
        private readonly TaskAssistantScheduleDbContextBuilder $scheduleDbContextBuilder,
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
                    );
                    $forcedPlan = $this->maybeRemapScheduleToPrioritize($thread, $forcedPlan);

                    $this->logRoutingDecision($thread, $assistantMessage, $forcedPlan);

                    $candidateSnapshot = $this->candidateProvider->candidatesForUser(
                        $thread->user,
                        taskLimit: 200,
                    );
                    if ($this->isWorkspaceCandidateSnapshotEmpty($candidateSnapshot)) {
                        $this->logWorkspaceEmptyShortcircuit($thread, $assistantMessageId, $forcedPlan->flow);
                        $this->runEmptyWorkspaceFlow($thread, $assistantMessage, $content, $forcedPlan);

                        return;
                    }

                    if ($forcedFlow === 'prioritize') {
                        $this->runPrioritizeFlow($thread, $assistantMessage, $content, $forcedPlan);

                        return;
                    }

                    $this->runScheduleFlow($thread, $userMessage, $assistantMessage, $content, $forcedPlan);

                    return;
                }
            }

            $pendingScheduleFallback = $this->conversationState->pendingScheduleFallback($thread);
            if ($pendingScheduleFallback !== null) {
                $handled = $this->handlePendingScheduleFallbackDecision(
                    thread: $thread,
                    assistantMessage: $assistantMessage,
                    userMessageContent: $content,
                    pendingState: $pendingScheduleFallback,
                );
                if ($handled) {
                    return;
                }
            }

            $plan = $this->buildExecutionPlan($thread, $content);
            $plan = $this->maybeRemapScheduleToPrioritize($thread, $plan);
            $plan = $this->maybeRewritePlanForScheduleRefinement($thread, $plan, $assistantMessage->id, $content);
            $this->logRoutingDecision($thread, $assistantMessage, $plan);

            if (in_array($plan->flow, ['prioritize', 'schedule', 'prioritize_schedule'], true)) {
                $candidateSnapshot = $this->candidateProvider->candidatesForUser(
                    $thread->user,
                    taskLimit: 200,
                );
                if ($this->isWorkspaceCandidateSnapshotEmpty($candidateSnapshot)) {
                    $this->logWorkspaceEmptyShortcircuit($thread, $assistantMessageId, $plan->flow);
                    $this->runEmptyWorkspaceFlow($thread, $assistantMessage, $content, $plan);

                    return;
                }
            }

            if ($plan->flow === 'general_guidance') {
                $this->runGeneralGuidanceFlow($thread, $assistantMessage, $content, $plan);

                return;
            }

            if ($plan->flow === 'prioritize') {
                $this->runPrioritizeFlow($thread, $assistantMessage, $content, $plan);

                return;
            }

            if ($plan->flow === 'prioritize_schedule') {
                $this->runPrioritizeScheduleFlow($thread, $userMessage, $assistantMessage, $content, $plan);

                return;
            }

            if ($plan->flow === 'schedule_refinement') {
                $this->runScheduleRefinementFlow($thread, $userMessage, $assistantMessage, $content, $plan);

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
        if (in_array('schedule_rerouted_no_listing_context', $plan->reasonCodes, true)) {
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
        ]);

        $context = $this->constraintsExtractor->extract($content);

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
                $allItems[] = $this->buildPrioritizeListingTaskRowFromRawTask($raw, $id, $title, $now, $timezone);

                continue;
            }

            $allItems[] = [
                'entity_type' => $type,
                'entity_id' => $id,
                'title' => $title,
            ];
        }

        $limit = max(1, min($plan->countLimit, 10));
        $items = array_values(array_slice($allItems, 0, $limit));
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
                'next_options_chip_texts' => [
                    TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText((string) __('Add a task')),
                    TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText((string) __('Try a broader question')),
                ],
                'filter_interpretation' => null,
                'assumptions' => null,
                'count_mismatch_explanation' => null,
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
                    'If you want, I can schedule it for later today, tomorrow, or later this week.'
                ),
                'next_options_chip_texts' => [
                    TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule it for later today'),
                    TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule it for tomorrow'),
                    TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule it later this week'),
                ],
            ];
        }

        if (! $hasMoreUnseen) {
            $nextOptions = 'That covers the top items for this request. If you want, I can schedule the top task for later today, schedule all of them for tomorrow, or schedule them later this week.';

            return [
                'next_options' => TaskAssistantPrioritizeOutputDefaults::clampNextField($nextOptions),
                'next_options_chip_texts' => [
                    TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule the top task for later today'),
                    TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule all of them for tomorrow'),
                    TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule them later this week'),
                ],
            ];
        }

        $nextOptions = 'If you want, I can schedule the top task for later today, schedule all of them for tomorrow, or schedule them later this week.';

        return [
            'next_options' => TaskAssistantPrioritizeOutputDefaults::clampNextField($nextOptions),
            'next_options_chip_texts' => [
                TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule the top task for later today'),
                TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule all of them for tomorrow'),
                TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule them later this week'),
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
        ];
    }

    private function maybeRewritePlanForScheduleRefinement(
        TaskAssistantThread $thread,
        ExecutionPlan $plan,
        int $currentAssistantMessageId,
        string $content,
    ): ExecutionPlan {
        if ($plan->flow === 'prioritize') {
            if (! $this->isLikelyScheduleRefinementEditPrompt($content)) {
                return $plan;
            }
        }

        // Combined "prioritize_schedule" prompts are intended to generate a fresh
        // schedule from ranked top tasks. When the user is not asking for an
        // edit/reorder, do not rewrite into schedule_refinement even if a
        // pending schedule draft exists.
        if ($plan->flow === 'prioritize_schedule' && ! $this->isLikelyScheduleRefinementEditPrompt($content)) {
            return $plan;
        }

        if ($plan->flow === 'schedule' && $plan->targetEntities !== []) {
            return $plan;
        }

        $draftSource = $this->findPendingScheduleDraftSourceMessage($thread, $currentAssistantMessageId);
        if ($draftSource === null) {
            return $plan;
        }

        $reasonCodes = array_values(array_unique(array_merge(
            $plan->reasonCodes,
            ['schedule_refinement_turn']
        )));

        return new ExecutionPlan(
            flow: 'schedule_refinement',
            confidence: $plan->confidence,
            clarificationNeeded: $plan->clarificationNeeded,
            clarificationQuestion: $plan->clarificationQuestion,
            reasonCodes: $reasonCodes,
            constraints: array_merge($plan->constraints, [
                'schedule_refinement_draft_message_id' => $draftSource->id,
            ]),
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

        $hasEditVerb = preg_match('/\b(move|set|change|edit|shift|push|swap|reorder|put|make|reschedule|adjust|do|bring|bump|drag|slide|delay|advance|pull|drop)\b/u', $normalized) === 1;
        $hasScheduleCue = preg_match(
            '/\b(first|second|third|last|\d+(?:st|nd|rd|th)|item|task|one|it|this|that|same one|before|after|later|earlier|tomorrow|today|tmrw|tomorow|next week|next|at\s+\d{1,2}|am|pm|minute|minutes|duration|shorter|longer)\b/u',
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

        return ($hasEditVerb && $hasScheduleCue) || $hasStandaloneReorderShape || $implicitEditPhrase;
    }

    private function findPendingScheduleDraftSourceMessage(TaskAssistantThread $thread, int $excludeAssistantMessageId): ?TaskAssistantMessage
    {
        return $thread->messages()
            ->where('role', MessageRole::Assistant)
            ->where('id', '!=', $excludeAssistantMessageId)
            ->orderByDesc('id')
            ->get()
            ->first(fn (TaskAssistantMessage $m): bool => $this->assistantMessageHasPendingSchedulableProposals($m));
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
        foreach ($proposals as $p) {
            if (! is_array($p)) {
                continue;
            }
            if (($p['status'] ?? 'pending') !== 'pending') {
                continue;
            }
            if (trim((string) ($p['title'] ?? '')) === 'No schedulable items found') {
                continue;
            }
            $ap = $p['apply_payload'] ?? null;
            if (is_array($ap) && $ap !== []) {
                return true;
            }
            $entityType = (string) ($p['entity_type'] ?? '');
            $entityId = (int) ($p['entity_id'] ?? 0);
            $start = (string) ($p['start_datetime'] ?? '');
            $end = (string) ($p['end_datetime'] ?? '');
            if ($entityType === 'task' && $entityId > 0 && $start !== '') {
                return true;
            }
            if ($entityType === 'event' && $entityId > 0 && $start !== '' && $end !== '') {
                return true;
            }
            if ($entityType === 'project' && $entityId > 0 && $start !== '') {
                return true;
            }
        }

        return false;
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
            $this->runGeneralGuidanceFlow($thread, $assistantMessage, $content, $plan);

            return;
        }

        $encoded = json_encode($sourceProposals);
        /** @var array<int, array<string, mixed>> $workingProposals */
        $workingProposals = is_string($encoded) ? json_decode($encoded, true) : [];
        if (! is_array($workingProposals)) {
            $workingProposals = $sourceProposals;
        }

        $proposalsBeforeRefinement = $workingProposals;

        $timezone = (string) config('app.timezone', 'UTC');

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
                $partialFailureNotes[] = trim((string) ($resolution['clarification_message'] ?? 'Please tell me which item to edit and the exact change.'));

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
            $this->conversationState->rememberScheduleContext($thread, $targets, $plan->timeWindowHint, $lastReferencedProposalUuids);

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

        $this->conversationState->rememberScheduleContext($thread, $targets, $plan->timeWindowHint, $referencedProposalUuids);
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
                'count_limit' => $plan->countLimit,
                'schedule_user_id' => $thread->user_id,
            ]
        );
        $result = $this->maybeConvertToScheduleFallbackConfirmation(
            thread: $thread,
            userMessageContent: $content,
            plan: $plan,
            generationResult: $result,
        );

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
        $referencedProposalUuids = array_values(array_filter(array_map(
            static function (mixed $p): string {
                if (! is_array($p)) {
                    return '';
                }

                $uuid = trim((string) ($p['proposal_uuid'] ?? $p['proposal_id'] ?? ''));
                if ($uuid === '') {
                    return '';
                }

                $status = (string) ($p['status'] ?? 'pending');
                if ($status !== 'pending') {
                    return '';
                }

                $title = trim((string) ($p['title'] ?? ''));
                if ($title === 'No schedulable items found') {
                    return '';
                }

                $applyPayload = $p['apply_payload'] ?? null;
                if (is_array($applyPayload) && $applyPayload !== []) {
                    return $uuid;
                }

                $entityType = (string) ($p['entity_type'] ?? '');
                $entityId = (int) ($p['entity_id'] ?? 0);
                $start = trim((string) ($p['start_datetime'] ?? ''));
                $end = trim((string) ($p['end_datetime'] ?? ''));

                if ($entityType === 'task' && $entityId > 0 && $start !== '') {
                    return $uuid;
                }
                if ($entityType === 'event' && $entityId > 0 && $start !== '' && $end !== '') {
                    return $uuid;
                }
                if ($entityType === 'project' && $entityId > 0 && $start !== '') {
                    return $uuid;
                }

                return '';
            },
            $proposals
        ), static fn (string $u): bool => $u !== ''));

        if (! $confirmationRequired) {
            $this->conversationState->rememberScheduleContext(
                $thread,
                $scheduleTargets,
                $timeWindowHint,
                $referencedProposalUuids,
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
        $tz = $timezone !== '' ? $timezone : (string) config('app.timezone', 'UTC');
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
     */
    private function maybeRemapScheduleToPrioritize(TaskAssistantThread $thread, ExecutionPlan $plan): ExecutionPlan
    {
        if ($plan->flow !== 'schedule') {
            return $plan;
        }
        if ($plan->targetEntities !== []) {
            return $plan;
        }
        if ($this->conversationState->lastListing($thread) !== null) {
            return $plan;
        }

        $reasonCodes = array_values(array_unique(array_merge(
            $plan->reasonCodes,
            ['schedule_rerouted_no_listing_context']
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
     * @param  array<int, array<string, mixed>>  $proposals
     */
    private function publishScheduleClarificationResponse(
        TaskAssistantThread $thread,
        TaskAssistantMessage $assistantMessage,
        array $proposals,
        string $clarification
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

        $content = trim($clarification).' For example: "move second to 8 pm" or "move quiz task to tomorrow 8 pm".';
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
        if (! (bool) config('task-assistant.schedule.refinement.llm_fallback_enabled', true)) {
            return null;
        }
        $llmFallbackAttempted = true;

        $extracted = $this->scheduleRefinementStructuredOpExtractor->tryExtract(
            $thread->user,
            $originalUserContent,
            $workingProposals,
        );
        if (! ($extracted['ok'] ?? false)) {
            return null;
        }

        $operations = is_array($extracted['operations'] ?? null) ? $extracted['operations'] : [];
        if ($operations === []) {
            return null;
        }

        $mutation = $this->scheduleDraftMutationService->applyOperations($workingProposals, $operations, $timezone);
        if (! ($mutation['ok'] ?? false)) {
            return null;
        }

        return [
            'proposals' => $mutation['proposals'],
            'referencedProposalUuids' => $this->proposalUuidsFromScheduleOperations($operations),
        ];
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

        if ($changed) {
            return $result;
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $data['framing'] = 'I kept your current schedule draft unchanged.';
        $data['reasoning'] = 'I need a more specific edit target before changing times.';
        $data['confirmation'] = 'Tell me exactly which item to edit (first, second, last, or title) and the new time/date/duration.';
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

        $data = $this->buildScheduleFallbackConfirmationData($data, $thread, $userMessageContent);
        $generationResult['data'] = $data;

        return $generationResult;
    }

    /**
     * @param  array<string, mixed>  $scheduleData
     */
    private function shouldRequireFallbackConfirmation(ExecutionPlan $plan, array $scheduleData): bool
    {
        if ($plan->timeWindowHint !== 'later') {
            return false;
        }

        $digest = is_array($scheduleData['placement_digest'] ?? null) ? $scheduleData['placement_digest'] : [];
        $fallbackMode = (string) ($digest['fallback_mode'] ?? '');

        return $fallbackMode === 'auto_relaxed_today_or_tomorrow';
    }

    /**
     * @param  array<string, mixed>  $scheduleData
     * @return array<string, mixed>
     */
    private function buildScheduleFallbackConfirmationData(array $scheduleData, TaskAssistantThread $thread, string $userMessageContent): array
    {
        $digest = is_array($scheduleData['placement_digest'] ?? null) ? $scheduleData['placement_digest'] : [];
        $placementDates = is_array($digest['placement_dates'] ?? null) ? $digest['placement_dates'] : [];
        $daysUsed = is_array($digest['days_used'] ?? null) ? $digest['days_used'] : [];
        $firstDate = is_string($placementDates[0] ?? null) ? $placementDates[0] : null;
        $datePhrase = $firstDate !== null ? CarbonImmutable::parse($firstDate)->format('M j, Y') : 'tomorrow';
        $requestedWindow = [
            'hint' => 'later',
            'label' => 'Later today',
        ];
        $prompt = "I could not fit everything later today, but I can place these on {$datePhrase}. Would you like me to use this plan?";
        $reasonMessage = 'There is not enough free time left in your requested "later today" window.';

        $scheduleData['confirmation_required'] = true;
        $scheduleData['awaiting_user_decision'] = true;
        $scheduleData['confirmation_context'] = [
            'reason_code' => 'later_window_not_feasible',
            'reason_message' => $reasonMessage,
            'requested_window' => $requestedWindow,
            'attempted_horizon' => [
                'mode' => 'single_day',
                'date' => CarbonImmutable::now((string) config('app.timezone', 'UTC'))->toDateString(),
            ],
            'fallback_horizon' => [
                'mode' => 'single_day',
                'dates' => $placementDates,
            ],
            'prompt' => $prompt,
            'options' => [
                'Yes, continue with tomorrow',
                'Pick another time window',
                'Cancel scheduling for now',
            ],
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
        ];
        $scheduleData['framing'] = 'I checked your request and prepared a backup plan so you can still make progress.';
        $scheduleData['reasoning'] = 'Nothing is final yet. I will only continue if you confirm.';
        $scheduleData['confirmation'] = $prompt;

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

    /**
     * @param  array{schedule_data: array<string, mixed>, time_window_hint: string|null, initial_user_message: string, created_at?: string|null}  $pendingState
     */
    private function handlePendingScheduleFallbackDecision(
        TaskAssistantThread $thread,
        TaskAssistantMessage $assistantMessage,
        string $userMessageContent,
        array $pendingState,
    ): bool {
        $decision = $this->classifyScheduleFallbackDecision($userMessageContent);
        if ($decision === 'confirm') {
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

        $pendingData = is_array($pendingState['schedule_data'] ?? null) ? $pendingState['schedule_data'] : [];
        $pendingProposals = is_array($pendingData['proposals'] ?? null) ? $pendingData['proposals'] : [];

        if ($decision === 'decline') {
            $this->conversationState->clearPendingScheduleFallback($thread);
            $this->publishScheduleClarificationResponse(
                thread: $thread,
                assistantMessage: $assistantMessage,
                proposals: $pendingProposals,
                clarification: 'No problem. Tell me the time window you prefer (for example: tomorrow morning, this week, or specific time).',
            );

            return true;
        }

        $this->publishScheduleClarificationResponse(
            thread: $thread,
            assistantMessage: $assistantMessage,
            proposals: $pendingProposals,
            clarification: 'Please confirm first. Reply with yes/confirm to continue, or tell me another preferred window.',
        );

        return true;
    }

    private function classifyScheduleFallbackDecision(string $content): string
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $content) ?? $content));
        if ($normalized === '') {
            return 'unclear';
        }

        $affirmativePatterns = [
            '/\byes\b/u',
            '/\bconfirm\b/u',
            '/\bgo ahead\b/u',
            '/\bproceed\b/u',
            '/\bdo it\b/u',
            '/\bsounds good\b/u',
            '/\bok(?:ay)?\b/u',
            '/\bfine\b/u',
        ];
        $negativePatterns = [
            '/\bno\b/u',
            '/\bdon\'t\b/u',
            '/\bdo not\b/u',
            '/\bnot now\b/u',
            '/\bcancel\b/u',
            '/\bnever mind\b/u',
            '/\bstop\b/u',
        ];

        $hasAffirmative = false;
        foreach ($affirmativePatterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                $hasAffirmative = true;
                break;
            }
        }

        $hasNegative = false;
        foreach ($negativePatterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                $hasNegative = true;
                break;
            }
        }

        if ($hasAffirmative && ! $hasNegative) {
            return 'confirm';
        }
        if ($hasNegative && ! $hasAffirmative) {
            return 'decline';
        }

        return 'unclear';
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
            'general_guidance' => 'general_guidance',
        };

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
        if ($explicitTaskTargets !== []) {
            $topTaskEntities = array_slice($explicitTaskTargets, 0, $plan->countLimit);
        } else {
            // Deterministically compute top-N tasks, then schedule only those tasks.
            $prioritizeContext = $this->constraintsExtractor->extract($content);

            $snapshot = $this->candidateProvider->candidatesForUser(
                $thread->user,
                taskLimit: 200,
            );

            $ranked = $this->prioritizationService->prioritizeFocus($snapshot, $prioritizeContext);
            $rankedTasks = array_values(array_filter($ranked, static function (mixed $c): bool {
                return is_array($c) && (string) ($c['type'] ?? 'task') === 'task' && (int) ($c['id'] ?? 0) > 0;
            }));

            $rankedTasks = array_slice($rankedTasks, 0, $plan->countLimit);
            $topTaskEntities = array_values(array_map(static function (mixed $c): array {
                $id = (int) ($c['id'] ?? 0);
                $title = trim((string) ($c['title'] ?? 'Untitled'));

                return [
                    'entity_type' => 'task',
                    'entity_id' => $id,
                    'title' => $title !== '' ? $title : 'Untitled',
                    'position' => 0,
                ];
            }, $rankedTasks));
        }

        // Ensure scheduling filters out events/projects (tasks-only correctness).
        // If there are no target tasks, we use a sentinel target ID so the generator
        // produces an "empty placement" schedule with no events/projects proposals.
        // Sentinel: must be non-empty `target_entities` so the scheduler applies
        // tasks-only filtering, but must not reference any real task id.
        $sentinelTaskId = 0;
        $generatorTargetEntities = $topTaskEntities !== []
            ? $topTaskEntities
            : [[
                'entity_type' => 'task',
                'entity_id' => $sentinelTaskId,
                'title' => 'Sentinel',
                'position' => 0,
            ]];

        $result = $this->structuredFlowGenerator->generateDailySchedule(
            thread: $thread,
            userMessageContent: $content,
            historyMessages: $historyMessages,
            options: [
                'target_entities' => $generatorTargetEntities,
                'time_window_hint' => $plan->timeWindowHint,
                'count_limit' => $plan->countLimit,
                'schedule_user_id' => $thread->user_id,
            ]
        );

        $result = $this->maybeConvertToScheduleFallbackConfirmation(
            thread: $thread,
            userMessageContent: $content,
            plan: $plan,
            generationResult: $result,
        );

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
        $referencedProposalUuids = array_values(array_filter(array_map(
            static function (mixed $p): string {
                if (! is_array($p)) {
                    return '';
                }

                $uuid = trim((string) ($p['proposal_uuid'] ?? $p['proposal_id'] ?? ''));
                if ($uuid === '') {
                    return '';
                }

                $status = (string) ($p['status'] ?? 'pending');
                if ($status !== 'pending') {
                    return '';
                }

                $title = trim((string) ($p['title'] ?? ''));
                if ($title === 'No schedulable items found') {
                    return '';
                }

                $applyPayload = $p['apply_payload'] ?? null;
                if (is_array($applyPayload) && $applyPayload !== []) {
                    return $uuid;
                }

                $entityType = (string) ($p['entity_type'] ?? '');
                $entityId = (int) ($p['entity_id'] ?? 0);
                $start = trim((string) ($p['start_datetime'] ?? ''));

                $end = trim((string) ($p['end_datetime'] ?? ''));

                if ($entityType === 'task' && $entityId > 0 && $start !== '') {
                    return $uuid;
                }

                if ($entityType === 'event' && $entityId > 0 && $start !== '' && $end !== '') {
                    return $uuid;
                }

                if ($entityType === 'project' && $entityId > 0 && $start !== '') {
                    return $uuid;
                }

                return '';
            },
            $proposals
        ), static fn (string $u): bool => $u !== ''));

        if (! $confirmationRequired) {
            $this->conversationState->rememberScheduleContext(
                $thread,
                $topTaskEntities,
                $plan->timeWindowHint,
                $referencedProposalUuids,
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
            'prioritize_variant' => $plan->flow === 'prioritize' ? TaskAssistantPrioritizeVariant::Rank->value : null,
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
