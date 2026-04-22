<?php

namespace App\Services\LLM\Scheduling;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\Prioritization\TaskPrioritizationService;
use App\Services\LLM\TaskAssistant\TaskAssistantHybridNarrativeService;
use App\Services\LLM\TaskAssistant\TaskAssistantPromptData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class TaskAssistantStructuredFlowGenerator
{
    private const SCHEDULE_SCHEMA_VERSION = 2;

    public function __construct(
        private readonly TaskAssistantPromptData $promptData,
        private readonly TaskAssistantScheduleDbContextBuilder $dbContextBuilder,
        private readonly TaskAssistantScheduleContextBuilder $scheduleContextBuilder,
        private readonly TaskPrioritizationService $prioritizationService,
        private readonly TaskAssistantHybridNarrativeService $hybridNarrative,
        private readonly TaskAssistantWindowPlacementService $windowPlacementService,
        private readonly ScheduleConfirmationSignalsBuilder $confirmationSignalsBuilder,
    ) {}

    /**
     * When the user refers to a concrete listing ("them") we resolve {@see $options} target_entities.
     * The message may still contain "this week" for placement horizon; that must not also apply
     * {@see TaskPrioritizationService} due-window filtering (e.g. this_week drops tasks due after ~7 days).
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function normalizeSchedulingContextForExplicitTargets(array $context, array $options): array
    {
        $targets = $options['target_entities'] ?? [];
        if (! is_array($targets) || $targets === []) {
            return $context;
        }

        $out = $context;
        $out['time_constraint'] = 'none';

        return $out;
    }

    /**
     * @param  Collection<int, mixed>  $historyMessages
     * @return array{valid: bool, data: array<string, mixed>, errors: array<int, string>}
     */
    public function generateDailySchedule(
        TaskAssistantThread $thread,
        string $userMessageContent,
        Collection $historyMessages,
        array $options = []
    ): array {
        $user = $thread->user;

        $runId = app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null;

        $promptData = $this->promptData->forUser($user);
        $built = $this->dbContextBuilder->buildForUser($user, $userMessageContent, $options);
        $context = $this->normalizeSchedulingContextForExplicitTargets($built['context'], $options);
        $snapshot = $built['snapshot'];

        Log::info('task-assistant.snapshot', [
            'layer' => 'structured_generation',
            'run_id' => $runId,
            'user_id' => $user->id,
            'thread_id' => $thread->id,
            'task_count' => count($snapshot['tasks'] ?? []),
            'event_count' => count($snapshot['events'] ?? []),
            'project_count' => count($snapshot['projects'] ?? []),
            'user_message_length' => mb_strlen($userMessageContent),
            'history_messages_count' => $historyMessages->count(),
        ]);

        $contextualSnapshot = $this->applyContextToSnapshot($snapshot, $context, $options);
        $promptData['snapshot'] = $contextualSnapshot;
        $promptData['user_context'] = $context;
        $promptData['schedule_horizon'] = $contextualSnapshot['schedule_horizon'] ?? $context['schedule_horizon'] ?? null;
        $promptData['schedule_source'] = is_string($options['schedule_source'] ?? null)
            ? (string) $options['schedule_source']
            : 'schedule';
        $countLimit = max(1, min((int) ($options['count_limit'] ?? 10), 10));

        [$proposals, $placementDigest] = $this->generateProposalsChunkedSpill($contextualSnapshot, $context, $countLimit, $options);
        $placementDigest = $this->confirmationSignalsBuilder->enrich(
            $contextualSnapshot,
            $context,
            $placementDigest,
            $proposals,
            $options
        );
        $promptData['placement_digest'] = $placementDigest;
        $timezoneName = (string) ($contextualSnapshot['timezone'] ?? config('app.timezone', 'Asia/Manila'));
        $blocks = $this->buildLegacyBlocksFromProposals($proposals, $timezoneName);
        $items = $this->buildScheduleItemsFromProposals($proposals);
        $deterministicSummary = $this->buildDeterministicSummary($context, $contextualSnapshot);

        $blocksJson = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $isEmptyPlacement = $this->isScheduleEmptyPlacement($proposals);
        $schedulableProposalCount = $this->countSchedulableProposals($proposals);

        $placementDigestJson = json_encode($placementDigest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        $narrative = $this->hybridNarrative->refineDailySchedule(
            $historyMessages,
            $promptData,
            $userMessageContent,
            (string) $blocksJson,
            $deterministicSummary,
            $thread->id,
            $user->id,
            $isEmptyPlacement,
            $schedulableProposalCount,
            'schedule_narrative',
            $placementDigestJson,
        );

        $horizon = $contextualSnapshot['schedule_horizon'] ?? null;
        $scheduleVariant = 'daily';
        if (is_array($horizon)
            && ($horizon['mode'] ?? '') === 'range'
            && isset($horizon['start_date'], $horizon['end_date'])
            && $horizon['start_date'] !== $horizon['end_date']) {
            $scheduleVariant = 'range';
        }
        $scheduleExplainability = $this->buildScheduleExplainability(
            snapshot: $contextualSnapshot,
            context: $context,
            proposals: $proposals,
            digest: $placementDigest,
            scheduleOptions: $options,
        );

        $data = [
            'schema_version' => self::SCHEDULE_SCHEMA_VERSION,
            'proposals' => $proposals,
            'blocks' => $blocks,
            'items' => $items,
            'schedule_variant' => $scheduleVariant,
            'framing' => $narrative['framing'],
            'reasoning' => $narrative['reasoning'],
            'confirmation' => $narrative['confirmation'],
            'schedule_empty_placement' => $isEmptyPlacement,
            'placement_digest' => $placementDigest,
            'window_selection_explanation' => $scheduleExplainability['window_selection_explanation'],
            'ordering_rationale' => $scheduleExplainability['ordering_rationale'],
            'blocking_reasons' => $scheduleExplainability['blocking_reasons'],
            'fallback_choice_explanation' => $scheduleExplainability['fallback_choice_explanation'],
            'window_selection_struct' => $scheduleExplainability['window_selection_struct'] ?? null,
            'ordering_rationale_struct' => $scheduleExplainability['ordering_rationale_struct'] ?? [],
            'blocking_reasons_struct' => $scheduleExplainability['blocking_reasons_struct'] ?? [],
            'confirmation_required' => false,
            'awaiting_user_decision' => false,
            'confirmation_context' => null,
            'fallback_preview' => null,
        ];

        $horizonLog = $contextualSnapshot['schedule_horizon'] ?? null;
        Log::info('task-assistant.structured_generation', [
            'layer' => 'structured_generation',
            'run_id' => $runId,
            'thread_id' => $thread->id,
            'user_id' => $user->id,
            'flow' => 'daily_schedule',
            'proposals_count' => count($proposals),
            'blocks_count' => count($blocks),
            'target_entities_in_options' => isset($options['target_entities']) && is_array($options['target_entities'])
                ? count($options['target_entities'])
                : 0,
            'time_window_hint' => $options['time_window_hint'] ?? null,
            'count_limit' => $countLimit,
            'schedule_mode' => is_array($horizonLog) ? ($horizonLog['mode'] ?? null) : null,
            'horizon_start' => is_array($horizonLog) ? ($horizonLog['start_date'] ?? null) : null,
            'horizon_end' => is_array($horizonLog) ? ($horizonLog['end_date'] ?? null) : null,
            'horizon_label' => is_array($horizonLog) ? ($horizonLog['label'] ?? null) : null,
        ]);

        return [
            'valid' => true,
            'data' => $data,
            'errors' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $options  Same shape as {@see generateDailySchedule} options (target_entities, time_window_hint)
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [schedule context, contextual snapshot]
     */
    public function buildSchedulePromptContext(array $snapshot, string $userMessage, array $options = []): array
    {
        $context = $this->scheduleContextBuilder->build($userMessage, $snapshot);
        $context = $this->normalizeSchedulingContextForExplicitTargets($context, $options);
        $contextualSnapshot = $this->applyContextToSnapshot($snapshot, $context, $options);

        return [$context, $contextualSnapshot];
    }

    /**
     * Resolve top-N task entities using the same improved scheduler snapshot/context pipeline.
     *
     * @param  array<int, array<string, mixed>>  $explicitTaskTargets
     * @return list<array{entity_type: 'task', entity_id: int, title: string, position: int}>
     */
    public function resolvePrioritizeScheduleTaskTargets(
        TaskAssistantThread $thread,
        string $userMessageContent,
        array $explicitTaskTargets,
        int $countLimit,
    ): array {
        $limit = max(1, $countLimit);
        if ($explicitTaskTargets !== []) {
            $filteredExplicitTargets = array_values(array_filter(
                $explicitTaskTargets,
                static function (array $entity): bool {
                    return (string) ($entity['status'] ?? '') !== TaskStatus::Doing->value;
                }
            ));
            $explicitTaskIds = array_values(array_filter(array_map(
                static fn (array $entity): int => (int) ($entity['entity_id'] ?? 0),
                $filteredExplicitTargets
            ), static fn (int $id): bool => $id > 0));
            if ($explicitTaskIds !== []) {
                $allowedTaskIds = Task::query()
                    ->forUser($thread->user_id)
                    ->whereIn('id', $explicitTaskIds)
                    ->where('status', '!=', TaskStatus::Doing->value)
                    ->pluck('id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();
                $allowedTaskLookup = array_fill_keys($allowedTaskIds, true);
                $filteredExplicitTargets = array_values(array_filter(
                    $filteredExplicitTargets,
                    static fn (array $entity): bool => isset($allowedTaskLookup[(int) ($entity['entity_id'] ?? 0)])
                ));
            }

            return array_values(array_map(
                static function (array $entity, int $index): array {
                    $title = trim((string) ($entity['title'] ?? 'Untitled'));

                    return [
                        'entity_type' => 'task',
                        'entity_id' => (int) ($entity['entity_id'] ?? 0),
                        'title' => $title !== '' ? $title : 'Untitled',
                        'position' => $index,
                    ];
                },
                array_slice($filteredExplicitTargets, 0, $limit),
                array_keys(array_slice($filteredExplicitTargets, 0, $limit))
            ));
        }

        $built = $this->dbContextBuilder->buildForUser(
            $thread->user,
            $userMessageContent,
            ['schedule_user_id' => $thread->user_id]
        );
        $context = is_array($built['context'] ?? null) ? $built['context'] : [];
        $snapshot = is_array($built['snapshot'] ?? null) ? $built['snapshot'] : [];
        $ranked = $this->prioritizationService->prioritizeFocus($snapshot, $context);
        $rankedTasks = array_values(array_filter($ranked, static function (mixed $candidate): bool {
            if (! is_array($candidate)) {
                return false;
            }
            $raw = is_array($candidate['raw'] ?? null) ? $candidate['raw'] : [];

            return (string) ($candidate['type'] ?? '') === 'task'
                && (int) ($candidate['id'] ?? 0) > 0
                && (string) ($raw['status'] ?? '') !== TaskStatus::Doing->value;
        }));

        return array_values(array_map(
            static function (array $candidate, int $index): array {
                $title = trim((string) ($candidate['title'] ?? 'Untitled'));

                return [
                    'entity_type' => 'task',
                    'entity_id' => (int) ($candidate['id'] ?? 0),
                    'title' => $title !== '' ? $title : 'Untitled',
                    'position' => $index,
                ];
            },
            array_slice($rankedTasks, 0, $limit),
            array_keys(array_slice($rankedTasks, 0, $limit))
        ));
    }

    /**
     * Build schedule payload + narrative from server-owned proposals (e.g. multiturn refinement).
     *
     * @param  Collection<int, mixed>  $historyMessages
     * @param  array<int, array<string, mixed>>  $proposals
     * @param  array<string, mixed>  $context  From {@see TaskAssistantScheduleContextBuilder::build}
     * @param  array<string, mixed>  $contextualSnapshot
     * @return array{valid: bool, data: array<string, mixed>, errors: array<int, string>}
     */
    public function composeDailyScheduleFromProposals(
        TaskAssistantThread $thread,
        Collection $historyMessages,
        string $userMessageContent,
        array $proposals,
        array $context,
        array $contextualSnapshot,
        string $narrativeGenerationRoute = 'schedule_narrative',
        ?string $placementDigestJson = null,
    ): array {
        $user = $thread->user;
        $runId = app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null;

        $digestJsonNorm = $placementDigestJson !== null ? trim($placementDigestJson) : '';
        if ($digestJsonNorm === '') {
            $digestJsonNorm = '{}';
        }
        $digestForPrompt = [];
        if ($digestJsonNorm !== '{}') {
            $decoded = json_decode($digestJsonNorm, true);
            if (is_array($decoded)) {
                $digestForPrompt = $decoded;
            }
        }

        $proposals = $this->regenerateApplyPayloadsForProposals($proposals);
        $digestForPrompt = $this->confirmationSignalsBuilder->enrich(
            $contextualSnapshot,
            $context,
            $digestForPrompt,
            $proposals,
            []
        );

        $promptData = $this->promptData->forUser($user);
        $promptData['snapshot'] = $contextualSnapshot;
        $promptData['user_context'] = $context;
        $promptData['schedule_horizon'] = $contextualSnapshot['schedule_horizon'] ?? $context['schedule_horizon'] ?? null;
        $promptData['schedule_source'] = is_string($context['schedule_source'] ?? null)
            ? (string) $context['schedule_source']
            : 'schedule';
        $promptData['placement_digest'] = $digestForPrompt;
        $timezoneName = (string) ($contextualSnapshot['timezone'] ?? config('app.timezone', 'Asia/Manila'));
        $blocks = $this->buildLegacyBlocksFromProposals($proposals, $timezoneName);
        $items = $this->buildScheduleItemsFromProposals($proposals);
        $deterministicSummary = $this->buildDeterministicSummary($context, $contextualSnapshot);
        $blocksJson = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $isEmptyPlacement = $this->isScheduleEmptyPlacement($proposals);
        $schedulableProposalCount = $this->countSchedulableProposals($proposals);

        $digestForNarrative = ($digestForPrompt !== [])
            ? (json_encode($digestForPrompt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null)
            : null;

        $narrative = $this->hybridNarrative->refineDailySchedule(
            $historyMessages,
            $promptData,
            $userMessageContent,
            (string) $blocksJson,
            $deterministicSummary,
            $thread->id,
            $user->id,
            $isEmptyPlacement,
            $schedulableProposalCount,
            $narrativeGenerationRoute,
            $digestForNarrative,
        );

        $horizon = $contextualSnapshot['schedule_horizon'] ?? null;
        $scheduleVariant = 'daily';
        if (is_array($horizon)
            && ($horizon['mode'] ?? '') === 'range'
            && isset($horizon['start_date'], $horizon['end_date'])
            && $horizon['start_date'] !== $horizon['end_date']) {
            $scheduleVariant = 'range';
        }
        $scheduleExplainability = $this->buildScheduleExplainability(
            snapshot: $contextualSnapshot,
            context: $context,
            proposals: $proposals,
            digest: $digestForPrompt,
            scheduleOptions: [],
        );

        $data = [
            'schema_version' => self::SCHEDULE_SCHEMA_VERSION,
            'proposals' => $proposals,
            'blocks' => $blocks,
            'items' => $items,
            'schedule_variant' => $scheduleVariant,
            'framing' => $narrative['framing'],
            'reasoning' => $narrative['reasoning'],
            'confirmation' => $narrative['confirmation'],
            'schedule_empty_placement' => $isEmptyPlacement,
            'placement_digest' => $digestForPrompt,
            'window_selection_explanation' => $scheduleExplainability['window_selection_explanation'],
            'ordering_rationale' => $scheduleExplainability['ordering_rationale'],
            'blocking_reasons' => $scheduleExplainability['blocking_reasons'],
            'fallback_choice_explanation' => $scheduleExplainability['fallback_choice_explanation'],
            'window_selection_struct' => $scheduleExplainability['window_selection_struct'] ?? null,
            'ordering_rationale_struct' => $scheduleExplainability['ordering_rationale_struct'] ?? [],
            'blocking_reasons_struct' => $scheduleExplainability['blocking_reasons_struct'] ?? [],
            'confirmation_required' => false,
            'awaiting_user_decision' => false,
            'confirmation_context' => null,
            'fallback_preview' => null,
        ];

        Log::info('task-assistant.structured_generation', [
            'layer' => 'structured_generation',
            'run_id' => $runId,
            'thread_id' => $thread->id,
            'user_id' => $user->id,
            'flow' => 'daily_schedule_compose',
            'proposals_count' => count($proposals),
            'blocks_count' => count($blocks),
            'narrative_route' => $narrativeGenerationRoute,
        ]);

        return [
            'valid' => true,
            'data' => $data,
            'errors' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $context
     * @param  list<array<string, mixed>>  $proposals
     * @param  array<string, mixed>  $digest
     * @param  array<string, mixed>  $scheduleOptions
     * @return array{
     *   window_selection_explanation: string,
     *   ordering_rationale: list<string>,
     *   blocking_reasons: list<array{title:string,blocked_window:string,reason:string}>,
     *   fallback_choice_explanation: string|null,
     *   window_selection_struct: array<string, mixed>,
     *   ordering_rationale_struct: list<array<string, mixed>>,
     *   blocking_reasons_struct: list<array<string, mixed>>
     * }
     */
    private function buildScheduleExplainability(
        array $snapshot,
        array $context,
        array $proposals,
        array $digest,
        array $scheduleOptions,
    ): array {
        $window = is_array($snapshot['time_window'] ?? null) ? $snapshot['time_window'] : [];
        $windowStart = is_string($window['start'] ?? null) ? (string) $window['start'] : '';
        $windowEnd = is_string($window['end'] ?? null) ? (string) $window['end'] : '';
        $horizon = is_array($snapshot['schedule_horizon'] ?? null) ? $snapshot['schedule_horizon'] : [];
        $horizonStart = is_string($horizon['start_date'] ?? null) ? (string) $horizon['start_date'] : '';
        $horizonEnd = is_string($horizon['end_date'] ?? null) ? (string) $horizon['end_date'] : '';
        $meaningfulWindow = $windowStart !== '' && $windowEnd !== '' && ! ($windowStart === '00:00' && $windowEnd === '23:59');

        $windowSelectionExplanation = $meaningfulWindow
            ? 'I prioritized slots between '.$this->formatClockLabel($windowStart).' and '.$this->formatClockLabel($windowEnd).' so this plan fits the time window you asked for.'
            : 'I chose the earliest realistic windows that avoid conflicts and keep your top items moving.';
        if ($horizonStart !== '' && $horizonEnd !== '' && $horizonStart !== $horizonEnd) {
            $windowSelectionExplanation .= " I spread placements across {$horizonStart} to {$horizonEnd} when needed.";
        }

        $orderingRationale = [];
        $orderingRationaleStruct = [];
        foreach ($proposals as $index => $proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            $title = trim((string) ($proposal['title'] ?? ''));
            if ($title === '' || $title === 'No schedulable items found') {
                continue;
            }
            $startRaw = trim((string) ($proposal['start_datetime'] ?? ''));
            $startLabel = '';
            if ($startRaw !== '') {
                try {
                    $startLabel = (new \DateTimeImmutable($startRaw))->format('M j g:i A');
                } catch (\Throwable) {
                    $startLabel = '';
                }
            }
            $orderingRationale[] = $startLabel !== ''
                ? '#'.($index + 1)." {$title}: placed at {$startLabel} as one of the strongest fit windows."
                : '#'.($index + 1)." {$title}: placed in the next strongest fit window.";
            $orderingRationaleStruct[] = [
                'rank' => $index + 1,
                'title' => $title,
                'slot_start' => $startRaw !== '' ? $startRaw : null,
                'fit_reason_code' => $startRaw !== '' ? 'strongest_fit_window' : 'next_fit_window',
                'fit_facts' => [
                    ['key' => 'has_explicit_slot', 'value' => $startRaw !== '' ? 'true' : 'false'],
                    ['key' => 'slot_label', 'value' => $startLabel !== '' ? $startLabel : 'n/a'],
                ],
            ];
        }

        $blockingReasons = [];
        $blockingReasonsStruct = [];
        $unplacedUnits = is_array($digest['unplaced_units'] ?? null) ? $digest['unplaced_units'] : [];
        foreach ($unplacedUnits as $unit) {
            if (! is_array($unit)) {
                continue;
            }
            $title = trim((string) ($unit['title'] ?? 'Unplaced item'));
            $reason = (string) ($unit['reason'] ?? 'horizon_exhausted');
            $reasonCode = match ($reason) {
                'count_limit' => 'count_limit_reached',
                'window_conflict' => 'window_conflict',
                default => 'horizon_exhausted',
            };
            $humanReason = match ($reason) {
                'count_limit' => 'Not scheduled yet because we reached the current item limit.',
                default => 'No free slot was available inside the requested schedule window.',
            };
            $blockedWindow = $meaningfulWindow
                ? $this->formatClockLabel($windowStart).'-'.$this->formatClockLabel($windowEnd)
                : (($horizonStart !== '' && $horizonEnd !== '') ? "{$horizonStart} to {$horizonEnd}" : 'current planning window');
            $blockingReasons[] = [
                'title' => $title !== '' ? $title : 'Unplaced item',
                'blocked_window' => $blockedWindow,
                'reason' => $humanReason,
            ];
            $blockingReasonsStruct[] = [
                'title' => $title !== '' ? $title : 'Unplaced item',
                'blocked_window' => $blockedWindow,
                'block_reason_code' => $reasonCode,
                'reason_facts' => [
                    ['key' => 'source_reason', 'value' => $reason],
                    ['key' => 'window_context', 'value' => $blockedWindow],
                ],
            ];
        }

        $busyBlockers = $this->collectRequestedWindowBusyBlockers($snapshot);
        foreach ($busyBlockers as $blocker) {
            $blockingReasons[] = $blocker;
        }
        $blockingReasons = array_slice($blockingReasons, 0, 8);

        $fallbackChoiceExplanation = null;
        $fallbackMode = trim((string) ($digest['fallback_mode'] ?? ''));
        if ($fallbackMode !== '') {
            $fallbackChoiceExplanation = match ($fallbackMode) {
                'auto_relaxed_today_or_tomorrow' => 'I widened placement to nearby days because the original window had no valid opening.',
                default => 'I used a safer fallback schedule strategy to keep your plan realistic.',
            };
        }

        return [
            'window_selection_explanation' => $windowSelectionExplanation,
            'ordering_rationale' => $orderingRationale,
            'blocking_reasons' => $blockingReasons,
            'fallback_choice_explanation' => $fallbackChoiceExplanation,
            'window_selection_struct' => [
                'window_mode' => $meaningfulWindow ? 'requested_window' : 'earliest_conflict_free',
                'window_used' => $meaningfulWindow
                    ? ['start' => $windowStart, 'end' => $windowEnd]
                    : null,
                'horizon_span' => ['start_date' => $horizonStart, 'end_date' => $horizonEnd],
                'fallback_used' => $fallbackMode !== '',
                'reason_code_primary' => $meaningfulWindow ? 'window_matched_request' : 'window_auto_selected',
            ],
            'ordering_rationale_struct' => $orderingRationaleStruct,
            'blocking_reasons_struct' => $blockingReasonsStruct,
        ];
    }

    private function formatClockLabel(string $time): string
    {
        $raw = trim($time);
        if ($raw === '') {
            return '';
        }

        $parsed = \DateTimeImmutable::createFromFormat('H:i', $raw);
        if (! $parsed instanceof \DateTimeImmutable) {
            return $raw;
        }

        return $parsed->format('g:i A');
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array{title:string,blocked_window:string,reason:string}>
     */
    private function collectRequestedWindowBusyBlockers(array $snapshot): array
    {
        $window = is_array($snapshot['time_window'] ?? null) ? $snapshot['time_window'] : [];
        $windowStart = is_string($window['start'] ?? null) ? trim((string) $window['start']) : '';
        $windowEnd = is_string($window['end'] ?? null) ? trim((string) $window['end']) : '';
        if ($windowStart === '' || $windowEnd === '' || ($windowStart === '00:00' && $windowEnd === '23:59')) {
            return [];
        }
        $horizon = is_array($snapshot['schedule_horizon'] ?? null) ? $snapshot['schedule_horizon'] : [];
        $day = is_string($horizon['start_date'] ?? null) ? (string) $horizon['start_date'] : '';
        if ($day === '') {
            return [];
        }

        $out = [];
        $events = is_array($snapshot['events_for_busy'] ?? null) ? $snapshot['events_for_busy'] : [];
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            $title = trim((string) ($event['title'] ?? 'Busy event'));
            $start = trim((string) ($event['starts_at'] ?? ''));
            $end = trim((string) ($event['ends_at'] ?? ''));
            if ($start === '' || $end === '') {
                continue;
            }
            try {
                $startDt = new \DateTimeImmutable($start);
                $endDt = new \DateTimeImmutable($end);
            } catch (\Throwable) {
                continue;
            }
            if ($startDt->format('Y-m-d') !== $day && $endDt->format('Y-m-d') !== $day) {
                continue;
            }
            $windowLabel = $startDt->format('g:i A').'-'.$endDt->format('g:i A');
            $out[] = [
                'title' => $title !== '' ? $title : 'Busy event',
                'blocked_window' => $windowLabel,
                'reason' => 'This event overlaps your requested time window.',
            ];
            if (count($out) >= 4) {
                break;
            }
        }

        $classIntervals = is_array($snapshot['school_class_busy_intervals'] ?? null) ? $snapshot['school_class_busy_intervals'] : [];
        foreach ($classIntervals as $interval) {
            if (! is_array($interval)) {
                continue;
            }
            $start = trim((string) ($interval['start'] ?? ''));
            $end = trim((string) ($interval['end'] ?? ''));
            if ($start === '' || $end === '') {
                continue;
            }
            try {
                $startDt = new \DateTimeImmutable($start);
                $endDt = new \DateTimeImmutable($end);
            } catch (\Throwable) {
                continue;
            }
            if ($startDt->format('Y-m-d') !== $day && $endDt->format('Y-m-d') !== $day) {
                continue;
            }
            $out[] = [
                'title' => 'School class',
                'blocked_window' => $startDt->format('g:i A').'-'.$endDt->format('g:i A'),
                'reason' => 'This class window overlaps your requested time.',
            ];
            if (count($out) >= 6) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     * @return array<int, array<string, mixed>>
     */
    public function regenerateApplyPayloadsForProposals(array $proposals): array
    {
        $out = [];
        foreach ($proposals as $p) {
            if (! is_array($p)) {
                continue;
            }
            $copy = $p;
            $uuid = trim((string) ($copy['proposal_uuid'] ?? ''));
            $legacyId = trim((string) ($copy['proposal_id'] ?? ''));
            if ($uuid === '') {
                $uuid = $legacyId !== '' ? $legacyId : (string) Str::uuid();
            }
            if ($legacyId === '') {
                $legacyId = $uuid;
            }
            $copy['proposal_uuid'] = $uuid;
            $copy['proposal_id'] = $legacyId;
            $copy['display_order'] = count($out);
            if (($copy['status'] ?? 'pending') !== 'pending') {
                $out[] = $copy;

                continue;
            }
            if (trim((string) ($copy['title'] ?? '')) === 'No schedulable items found') {
                $out[] = $copy;

                continue;
            }
            $startRaw = (string) ($copy['start_datetime'] ?? '');
            if ($startRaw === '') {
                $out[] = $copy;

                continue;
            }
            try {
                $startAt = new \DateTimeImmutable($startRaw);
            } catch (\Throwable) {
                $out[] = $copy;

                continue;
            }
            $entityType = (string) ($copy['entity_type'] ?? '');
            $minutes = (int) ($copy['duration_minutes'] ?? 30);
            if ($minutes < 1) {
                $minutes = 30;
            }
            $endAt = null;
            $endRaw = (string) ($copy['end_datetime'] ?? '');
            if ($endRaw !== '') {
                try {
                    $endAt = new \DateTimeImmutable($endRaw);
                } catch (\Throwable) {
                    $endAt = null;
                }
            }
            if ($entityType === 'project') {
                $candidate = [
                    'entity_type' => 'project',
                    'entity_id' => (int) ($copy['entity_id'] ?? 0),
                    'title' => (string) ($copy['title'] ?? ''),
                    'duration_minutes' => $minutes,
                ];
                $copy['apply_payload'] = $this->buildApplyPayload($candidate, $startAt, $startAt, $minutes);

                $out[] = $copy;

                continue;
            }
            if ($endAt === null) {
                $endAt = $entityType === 'event' ? $startAt : $startAt->modify("+{$minutes} minutes");
            }
            if ($entityType === 'event') {
                $minutes = (int) max(1, ($endAt->getTimestamp() - $startAt->getTimestamp()) / 60);
            }
            $candidate = [
                'entity_type' => $entityType,
                'entity_id' => (int) ($copy['entity_id'] ?? 0),
                'title' => (string) ($copy['title'] ?? ''),
                'duration_minutes' => $minutes,
                'schedule_apply_as' => $copy['schedule_apply_as'] ?? null,
            ];
            $copy['apply_payload'] = $this->buildApplyPayload($candidate, $startAt, $endAt, $minutes);
            if ($entityType === 'event' && $endRaw !== '') {
                $copy['end_datetime'] = $endAt->format(\DateTimeInterface::ATOM);
            }
            if ($entityType === 'task') {
                $copy['duration_minutes'] = $minutes;
                $copy['end_datetime'] = $endAt->format(\DateTimeInterface::ATOM);
            }
            $out[] = $copy;
        }

        return $out;
    }

    /**
     * Apply context filtering to snapshot for scheduling.
     *
     * Due-window narrowing (`today` / `this_week`) belongs in prioritize/listing; scheduling uses
     * resolved target entities, horizon, and time-window hints for placement only.
     *
     * @return array<string, mixed>
     */
    private function applyContextToSnapshot(array $snapshot, array $context, array $options = []): array
    {
        $contextualSnapshot = $snapshot;
        $eventsForBusy = is_array($contextualSnapshot['events'] ?? null)
            ? $contextualSnapshot['events']
            : [];
        $contextualSnapshot['events_for_busy'] = $eventsForBusy;

        $targetEntities = $options['target_entities'] ?? [];
        if (is_array($targetEntities) && $targetEntities !== []) {
            $taskIds = [];
            $eventIds = [];
            $projectIds = [];

            foreach ($targetEntities as $entity) {
                if (! is_array($entity)) {
                    continue;
                }
                $type = (string) ($entity['entity_type'] ?? '');
                $id = (int) ($entity['entity_id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                if ($type === 'task') {
                    $taskIds[] = $id;
                } elseif ($type === 'event') {
                    $eventIds[] = $id;
                } elseif ($type === 'project') {
                    $projectIds[] = $id;
                }
            }

            $contextualSnapshot['tasks'] = array_values(array_filter(
                $contextualSnapshot['tasks'] ?? [],
                fn (array $task): bool => in_array((int) ($task['id'] ?? 0), $taskIds, true)
            ));
            $contextualSnapshot['events'] = array_values(array_filter(
                $contextualSnapshot['events'] ?? [],
                fn (array $event): bool => in_array((int) ($event['id'] ?? 0), $eventIds, true)
            ));
            $contextualSnapshot['projects'] = array_values(array_filter(
                $contextualSnapshot['projects'] ?? [],
                fn (array $project): bool => in_array((int) ($project['id'] ?? 0), $projectIds, true)
            ));

            $scheduleUserId = (int) ($options['schedule_user_id'] ?? 0);
            if ($scheduleUserId > 0 && $taskIds !== []) {
                $contextualSnapshot = $this->mergeMissingTargetTasksForSchedule($contextualSnapshot, $taskIds, $scheduleUserId);
            }
        }

        $schedulingScope = (string) ($options['scheduling_scope'] ?? 'mixed');
        if ($schedulingScope === 'tasks_only') {
            $contextualSnapshot['events'] = [];
            $contextualSnapshot['projects'] = [];
        }

        if (! empty($context['recurring_requested'])) {
            $recurringOnly = array_values(array_filter(
                $contextualSnapshot['tasks'] ?? [],
                static fn (array $task): bool => ! empty($task['is_recurring'])
            ));
            if ($recurringOnly !== []) {
                $contextualSnapshot['tasks'] = $recurringOnly;
            }
        }

        if (! empty($context['priority_filters'])) {
            $filtered = collect($contextualSnapshot['tasks'] ?? [])
                ->filter(function (array $task) use ($context): bool {
                    return in_array($task['priority'] ?? 'medium', $context['priority_filters'], true);
                })
                ->values()
                ->all();
            if ($filtered !== []) {
                $contextualSnapshot['tasks'] = $filtered;
            }
        }

        if (! empty($context['task_keywords'])) {
            $filtered = collect($contextualSnapshot['tasks'] ?? [])
                ->filter(function (array $task) use ($context): bool {
                    $title = strtolower($task['title'] ?? '');
                    foreach ($context['task_keywords'] as $keyword) {
                        if ($keyword !== null && str_contains($title, strtolower((string) $keyword))) {
                            return true;
                        }
                    }

                    return false;
                })
                ->values()
                ->all();
            if ($filtered !== []) {
                $contextualSnapshot['tasks'] = $filtered;
            }
        }

        $ctxWindow = $context['time_window'] ?? null;
        if (is_array($ctxWindow) && isset($ctxWindow['start'], $ctxWindow['end'])) {
            $start = trim((string) $ctxWindow['start']);
            $end = trim((string) $ctxWindow['end']);
            if ($start !== '' && $end !== '') {
                $contextualSnapshot['time_window'] = ['start' => $start, 'end' => $end];
            }
        }

        // Backwards-compatible fallback: accept legacy time_window_hint.
        if (! isset($contextualSnapshot['time_window'])) {
            $timeWindowHint = $options['time_window_hint'] ?? null;
            if ($timeWindowHint === 'later_afternoon') {
                $contextualSnapshot['time_window'] = ['start' => '15:00', 'end' => '18:00'];
            } elseif ($timeWindowHint === 'afternoon_onwards') {
                $contextualSnapshot['time_window'] = ['start' => '15:00', 'end' => '22:00'];
            } elseif ($timeWindowHint === 'afternoon_evening') {
                $contextualSnapshot['time_window'] = ['start' => '15:00', 'end' => '22:00'];
            } elseif ($timeWindowHint === 'morning') {
                $contextualSnapshot['time_window'] = ['start' => '08:00', 'end' => '12:00'];
            } elseif ($timeWindowHint === 'morning_onwards') {
                $contextualSnapshot['time_window'] = ['start' => '08:00', 'end' => '22:00'];
            } elseif ($timeWindowHint === 'morning_afternoon') {
                $contextualSnapshot['time_window'] = ['start' => '08:00', 'end' => '18:00'];
            } elseif ($timeWindowHint === 'morning_evening') {
                $contextualSnapshot['time_window'] = ['start' => '08:00', 'end' => '22:00'];
            } elseif ($timeWindowHint === 'evening') {
                $contextualSnapshot['time_window'] = ['start' => '18:00', 'end' => '22:00'];
            } elseif ($timeWindowHint === 'later') {
                $contextualSnapshot['time_window'] = ['start' => '13:00', 'end' => '22:00'];
            }
        }

        $anchorAdjusted = $this->resolveClassAwareAnchorWindow($contextualSnapshot, $context);
        if (is_array($anchorAdjusted) && isset($anchorAdjusted['start'], $anchorAdjusted['end'])) {
            $contextualSnapshot['time_window'] = $anchorAdjusted;
        }

        $horizon = $context['schedule_horizon'] ?? null;
        if (is_array($horizon) && isset($horizon['start_date'], $horizon['end_date'])) {
            $contextualSnapshot['schedule_horizon'] = $horizon;
            if (($horizon['mode'] ?? '') === 'single_day' || $horizon['start_date'] === $horizon['end_date']) {
                $contextualSnapshot['today'] = $horizon['start_date'];
            }
        }

        return $contextualSnapshot;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $context
     * @return array{start: string, end: string}|null
     */
    private function resolveClassAwareAnchorWindow(array $snapshot, array $context): ?array
    {
        $reasonCodes = is_array($context['schedule_intent_reason_codes'] ?? null)
            ? $context['schedule_intent_reason_codes']
            : [];
        $hasClassAnchor = false;
        foreach ($reasonCodes as $code) {
            if (! is_string($code)) {
                continue;
            }
            if (str_contains($code, 'after_anchor_class') || str_contains($code, 'after_anchor_school')) {
                $hasClassAnchor = true;
                break;
            }
        }

        if (! $hasClassAnchor) {
            return null;
        }

        $horizon = is_array($snapshot['schedule_horizon'] ?? null) ? $snapshot['schedule_horizon'] : [];
        $targetDay = is_string($horizon['start_date'] ?? null)
            ? (string) $horizon['start_date']
            : (string) ($snapshot['today'] ?? '');
        if ($targetDay === '') {
            return null;
        }

        $intervals = is_array($snapshot['school_class_busy_intervals'] ?? null)
            ? $snapshot['school_class_busy_intervals']
            : [];
        $latestEnd = null;
        foreach ($intervals as $interval) {
            if (! is_array($interval) || ! is_string($interval['end'] ?? null)) {
                continue;
            }
            try {
                $end = new \DateTimeImmutable((string) $interval['end']);
            } catch (\Throwable) {
                continue;
            }

            if ($end->format('Y-m-d') !== $targetDay) {
                continue;
            }

            if (! $latestEnd instanceof \DateTimeImmutable || $end > $latestEnd) {
                $latestEnd = $end;
            }
        }

        if (! $latestEnd instanceof \DateTimeImmutable) {
            return null;
        }

        $currentWindow = is_array($snapshot['time_window'] ?? null) ? $snapshot['time_window'] : [];
        $fallbackEnd = is_string($currentWindow['end'] ?? null) ? (string) $currentWindow['end'] : '22:00';
        $start = $latestEnd->format('H:i');
        if ($start >= $fallbackEnd) {
            $start = $fallbackEnd;
        }

        return ['start' => $start, 'end' => $fallbackEnd];
    }

    /**
     * @param  array<string, mixed>  $contextualSnapshot
     * @param  list<int>  $taskIds
     * @return array<string, mixed>
     */
    private function mergeMissingTargetTasksForSchedule(array $contextualSnapshot, array $taskIds, int $userId): array
    {
        $existing = [];
        foreach ($contextualSnapshot['tasks'] ?? [] as $t) {
            if (is_array($t) && isset($t['id'])) {
                $existing[(int) $t['id']] = true;
            }
        }

        $uniqueTargetIds = array_values(array_unique(array_values(array_filter($taskIds, static fn (int $id): bool => $id > 0))));
        $missing = [];
        foreach ($uniqueTargetIds as $tid) {
            if (! isset($existing[$tid])) {
                $missing[] = $tid;
            }
        }

        if ($missing === []) {
            return $contextualSnapshot;
        }

        $skips = is_array($contextualSnapshot['schedule_target_skips'] ?? null)
            ? $contextualSnapshot['schedule_target_skips']
            : [];

        $tasks = is_array($contextualSnapshot['tasks'] ?? null) ? $contextualSnapshot['tasks'] : [];

        $fetched = Task::query()
            ->with(['tags', 'recurringTask', 'schoolClass.teacher'])
            ->forUser($userId)
            ->whereIn('id', $missing)
            ->get()
            ->keyBy(static fn (Task $task): int => $task->id);

        foreach ($missing as $mid) {
            $task = $fetched->get($mid);
            if (! $task instanceof Task) {
                $skips[] = [
                    'entity_type' => 'task',
                    'entity_id' => $mid,
                    'reason' => 'task_not_found',
                ];

                continue;
            }
            if ($task->completed_at !== null) {
                $skips[] = [
                    'entity_type' => 'task',
                    'entity_id' => $mid,
                    'reason' => 'task_completed',
                ];

                continue;
            }

            $tasks[] = [
                'id' => $task->id,
                'title' => Str::limit((string) $task->title, 160),
                'subject_name' => $task->subject_name,
                'teacher_name' => $task->resolvedTeacherName(),
                'tags' => $task->tags->pluck('name')->values()->all(),
                'status' => $task->status?->value,
                'priority' => $task->priority?->value,
                'complexity' => $task->complexity?->value,
                'ends_at' => $task->end_datetime?->toIso8601String(),
                'project_id' => $task->project_id,
                'event_id' => $task->event_id,
                'school_class_id' => $task->school_class_id,
                'duration_minutes' => $task->duration,
                'is_recurring' => $task->recurringTask !== null,
            ];
        }

        $contextualSnapshot['tasks'] = array_values($tasks);
        $contextualSnapshot['schedule_target_skips'] = array_values($skips);

        return $contextualSnapshot;
    }

    /**
     * @param  array<string, mixed>  $scheduleOptions
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function generateProposalsChunkedSpill(array $snapshot, array $context, int $countLimit, array $scheduleOptions = []): array
    {
        return $this->generateProposalsChunkedSpillCore($snapshot, $context, $countLimit, null, $scheduleOptions);
    }

    /**
     * @param  list<array<string, mixed>>|null  $unitsOverride  When set, skips candidate building and places only these units (refinement / tests).
     * @param  array<string, mixed>  $scheduleOptions  Original options from {@see generateDailySchedule} (target_entities, count_limit, etc.) for consumer UX flags.
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function generateProposalsChunkedSpillCore(array $snapshot, array $context, int $countLimit, ?array $unitsOverride, array $scheduleOptions = []): array
    {
        $timezone = new \DateTimeZone((string) ($snapshot['timezone'] ?? config('app.timezone', 'Asia/Manila')));
        $adaptiveAttempted = (bool) ($context['_adaptive_fallback_attempted'] ?? false);
        $requestedCount = $this->resolveRequestedCount($countLimit, $scheduleOptions);
        $requestedCountSource = $this->resolveRequestedCountSource($scheduleOptions);
        $placementDates = $this->resolvePlacementDates($snapshot, $timezone);
        $horizon = is_array($snapshot['schedule_horizon'] ?? null) ? $snapshot['schedule_horizon'] : [];
        $isRange = ($horizon['mode'] ?? '') === 'range'
            && isset($horizon['start_date'], $horizon['end_date'])
            && (string) $horizon['start_date'] !== (string) $horizon['end_date'];
        if (! $isRange) {
            $placementDates = array_slice($placementDates, 0, 1);
        }
        $window = is_array($snapshot['time_window'] ?? null) ? $snapshot['time_window'] : null;
        $windowStart = is_string($window['start'] ?? null) ? $window['start'] : '00:00';
        $windowEnd = is_string($window['end'] ?? null) ? $window['end'] : '23:59:59';
        $defaultAsapMode = (bool) ($context['default_asap_mode'] ?? false);

        $todayStr = is_string($snapshot['today'] ?? null) ? trim((string) $snapshot['today']) : '';
        $nowStr = is_string($snapshot['now'] ?? null) ? trim((string) $snapshot['now']) : '';
        $nowInstant = null;
        if ($nowStr !== '') {
            try {
                $nowInstant = new \DateTimeImmutable($nowStr, $timezone);
            } catch (\Throwable) {
                $nowInstant = null;
            }
        }

        /** @var array<string, array<int, array{start: \DateTimeImmutable, end: \DateTimeImmutable}>> $windowsByDay */
        $windowsByDay = [];
        foreach ($placementDates as $day) {
            $dayStart = new \DateTimeImmutable($day.' '.$windowStart, $timezone);
            $dayEnd = new \DateTimeImmutable($day.' '.$windowEnd, $timezone);

            // For the current day, avoid proposing new blocks in the past. If
            // \"now\" lies within today's window, clamp the effective start of
            // the scheduling window to that instant. If \"now\" is already
            // beyond the end of the window, there is nothing left to schedule
            // today so we skip free windows for this date entirely.
            if ($todayStr !== '' && $day === $todayStr && $nowInstant instanceof \DateTimeImmutable) {
                if ($nowInstant >= $dayEnd) {
                    $windowsByDay[$day] = [];

                    continue;
                }

                if ($nowInstant > $dayStart && $nowInstant < $dayEnd) {
                    $dayStart = $nowInstant;
                }
            }

            $busyRanges = $this->buildBusyRanges($snapshot, $dayStart, $dayEnd, $timezone);
            $windowsByDay[$day] = $this->buildFreeWindows($busyRanges, $dayStart, $dayEnd);
        }

        $skippedTargets = [];

        if ($unitsOverride !== null) {
            $units = array_values($unitsOverride);
            usort($units, fn (array $a, array $b): int => $this->compareSchedulingUnits($a, $b));
        } else {
            $build = $this->buildSchedulingCandidates($snapshot, $context);
            $candidates = is_array($build['candidates'] ?? null) ? $build['candidates'] : [];
            $skippedTargets = is_array($build['skipped_targets'] ?? null) ? $build['skipped_targets'] : [];
            usort($candidates, fn (array $a, array $b): int => ($b['score'] <=> $a['score']));

            $units = $this->expandCandidatesToSchedulingUnits($candidates);
            usort($units, fn (array $a, array $b): int => $this->compareSchedulingUnits($a, $b));
        }

        $candidateUnitCount = count($units);
        if ($requestedCountSource !== 'explicit_user' && $candidateUnitCount > 0) {
            $requestedCount = min($requestedCount, $candidateUnitCount);
        }

        $digest = [
            'requested_count' => $requestedCount,
            'requested_count_source' => $requestedCountSource,
            'candidate_units_count' => $candidateUnitCount,
            'time_window_hint' => is_string($scheduleOptions['time_window_hint'] ?? null) ? $scheduleOptions['time_window_hint'] : null,
            'placement_dates' => $placementDates,
            'days_used' => [],
            'skipped_targets' => is_array($snapshot['schedule_target_skips'] ?? null)
                ? $snapshot['schedule_target_skips']
                : [],
            'unplaced_units' => [],
            'partial_units' => [],
            'full_placed_count' => 0,
            'partial_placed_count' => 0,
            'count_shortfall' => 0,
            'top_n_shortfall' => false,
            'summary' => '',
            'attempted_horizon' => [
                'mode' => (string) ($horizon['mode'] ?? 'single_day'),
                'start_date' => is_string($horizon['start_date'] ?? null) ? $horizon['start_date'] : null,
                'end_date' => is_string($horizon['end_date'] ?? null) ? $horizon['end_date'] : null,
                'label' => is_string($horizon['label'] ?? null) ? $horizon['label'] : null,
            ],
            'default_asap_mode' => $defaultAsapMode,
        ];
        $strictRequestedDay = $this->resolveStrictRequestedDayFromSnapshot($snapshot);
        if ($strictRequestedDay !== null) {
            $digest['strict_day_requested'] = true;
            $digest['strict_day_date'] = $strictRequestedDay;
        }

        if ($skippedTargets !== []) {
            $digest['skipped_targets'] = array_values(array_merge(
                is_array($digest['skipped_targets'] ?? null) ? $digest['skipped_targets'] : [],
                $skippedTargets
            ));
        }

        $proposals = [];
        /** @var array<int, int> $taskPlacedChunks */
        $taskPlacedChunks = [];
        /** @var \DateTimeImmutable|null $lastPlacedStartAt */
        $lastPlacedStartAt = null;

        $anchorDay = $placementDates[0] ?? (string) ($snapshot['today'] ?? now($timezone)->format('Y-m-d'));
        $anchorStart = new \DateTimeImmutable($anchorDay.' '.$windowStart, $timezone);
        $selectionAnchor = $lastPlacedStartAt instanceof \DateTimeImmutable
            ? $lastPlacedStartAt
            : ($nowInstant instanceof \DateTimeImmutable ? $nowInstant : $anchorStart);

        $skipMorning = (bool) ($context['_refinement_skip_morning_shortcut'] ?? false)
            || $this->shouldSkipMorningShortcutForSnapshot($snapshot)
            || $defaultAsapMode;
        if (! $skipMorning && $units !== [] && count($proposals) < $countLimit) {
            $topUnit = $units[0];
            if (($topUnit['entity_type'] ?? '') === 'task') {
                $morningPlacement = $this->tryPlaceTopTaskInMorning(
                    unit: $topUnit,
                    placementDates: $placementDates,
                    windowsByDay: $windowsByDay,
                    proposals: $proposals,
                    taskPlacedChunks: $taskPlacedChunks,
                    digest: $digest,
                );

                if (($morningPlacement['placed'] ?? false) === true) {
                    $proposals = $morningPlacement['proposals'];
                    $windowsByDay = $morningPlacement['windowsByDay'];
                    $taskPlacedChunks = $morningPlacement['taskPlacedChunks'];
                    $digest = $morningPlacement['digest'];
                    array_shift($units);

                    // Enforce monotonic start ordering for subsequent placements.
                    $firstPlaced = $proposals[0] ?? null;
                    $firstStartRaw = is_array($firstPlaced) ? (string) ($firstPlaced['start_datetime'] ?? '') : '';
                    if ($firstStartRaw !== '') {
                        try {
                            $lastPlacedStartAt = new \DateTimeImmutable($firstStartRaw);
                        } catch (\Throwable) {
                            $lastPlacedStartAt = null;
                        }
                    }
                }
            }
        }

        $totalUnits = count($units);
        $disablePartial = (bool) ($context['_refinement_disable_partial_fit'] ?? false);

        foreach ($units as $unitIndex => $unit) {
            if (count($proposals) >= $countLimit) {
                $digest['unplaced_units'][] = [
                    'entity_type' => $unit['entity_type'],
                    'entity_id' => $unit['entity_id'],
                    'title' => $unit['title'],
                    'minutes' => $unit['minutes'],
                    'reason' => 'count_limit',
                ];

                continue;
            }

            $placed = false;
            foreach ($placementDates as $day) {
                $freeWindows = $windowsByDay[$day] ?? [];
                if ($lastPlacedStartAt instanceof \DateTimeImmutable) {
                    $freeWindows = array_values(array_filter(
                        $freeWindows,
                        static fn (array $w): bool => ($w['start'] ?? null) instanceof \DateTimeImmutable
                            && ($w['start'] >= $lastPlacedStartAt)
                    ));
                }
                $blockMinutes = max(1, (int) ($unit['minutes'] ?? 30));
                $proposalsCountAfterPlacement = count($proposals) + 1;
                // If we still need to place more items after this one, reserve a gap
                // so the next block doesn't feel back-to-back.
                $hasMoreUnitsAfterThis = $unitIndex < ($totalUnits - 1);
                $gapMinutes = $hasMoreUnitsAfterThis
                    && $proposalsCountAfterPlacement < $countLimit
                    ? $this->computeBetweenBlockGapMinutes($blockMinutes)
                    : 0;
                $requiredMinutes = $blockMinutes + $gapMinutes;

                $fitted = $this->windowPlacementService->selectBestFittingWindow(
                    windows: $freeWindows,
                    requiredMinutes: $requiredMinutes,
                    unit: $unit,
                    snapshot: $snapshot,
                    minStartAt: $lastPlacedStartAt instanceof \DateTimeImmutable ? $lastPlacedStartAt : $selectionAnchor,
                    defaultAsapMode: $defaultAsapMode,
                );
                if ($fitted === null) {
                    continue;
                }

                [$windowIndex, $startAt] = $fitted;
                $blockEndAt = $startAt->modify("+{$blockMinutes} minutes");
                $consumeEndAt = $gapMinutes > 0 ? $blockEndAt->modify("+{$gapMinutes} minutes") : $blockEndAt;
                $candidate = [
                    'entity_type' => $unit['entity_type'],
                    'entity_id' => $unit['entity_id'],
                    'title' => $unit['title'],
                    'score' => $unit['score'],
                    'duration_minutes' => $blockMinutes,
                    'priority_rank' => (int) ($unit['priority_rank'] ?? ($unitIndex + 1)),
                ];

                if ($unit['entity_type'] === 'task') {
                    $tid = (int) $unit['entity_id'];
                    $prior = $taskPlacedChunks[$tid] ?? 0;
                    $applyAs = $unit['_refinement_schedule_apply_as'] ?? null;
                    if (is_string($applyAs) && $applyAs !== '') {
                        $candidate['schedule_apply_as'] = $applyAs;
                    } else {
                        $candidate['schedule_apply_as'] = $prior === 0 ? 'update_task' : 'create_event';
                    }
                    $taskPlacedChunks[$tid] = $prior + 1;
                } elseif (isset($unit['_refinement_schedule_apply_as']) && is_string($unit['_refinement_schedule_apply_as'])) {
                    $candidate['schedule_apply_as'] = $unit['_refinement_schedule_apply_as'];
                }

                $proposals[] = $this->makeProposal($candidate, $startAt, $blockEndAt, $blockMinutes);
                $windowsByDay[$day] = $this->consumeWindow($freeWindows, $windowIndex, $startAt, $consumeEndAt);

                if (! in_array($day, $digest['days_used'], true)) {
                    $digest['days_used'][] = $day;
                }
                $placed = true;
                $lastPlacedStartAt = $startAt;

                break;
            }

            if (! $placed) {
                if (! $disablePartial
                    && ($unit['entity_type'] ?? '') === 'task'
                    && $this->canUsePartialPlacementForUnit($unit, $scheduleOptions, $requestedCount)
                ) {
                    $partial = $this->placePartialTaskUnit(
                        unit: $unit,
                        placementDates: $placementDates,
                        windowsByDay: $windowsByDay,
                        timezone: $timezone,
                        proposals: $proposals,
                        countLimit: $countLimit,
                        taskPlacedChunks: $taskPlacedChunks,
                        digest: $digest,
                        minStartAt: $lastPlacedStartAt,
                    );
                    if ($partial['placed'] ?? false) {
                        $proposals = $partial['proposals'];
                        $windowsByDay = $partial['windowsByDay'];
                        $digest = $partial['digest'];
                        $placed = true;

                        $last = $proposals[array_key_last($proposals)] ?? null;
                        $lastStartRaw = is_array($last) ? (string) ($last['start_datetime'] ?? '') : '';
                        if ($lastStartRaw !== '') {
                            try {
                                $lastPlacedStartAt = new \DateTimeImmutable($lastStartRaw);
                            } catch (\Throwable) {
                                // Keep as-is.
                            }
                        }
                    }
                }

                if (! $placed) {
                    $digest['unplaced_units'][] = [
                        'entity_type' => $unit['entity_type'],
                        'entity_id' => $unit['entity_id'],
                        'title' => $unit['title'],
                        'minutes' => $unit['minutes'],
                        'reason' => 'horizon_exhausted',
                    ];
                }
            }
        }

        $digest['summary'] = sprintf(
            'placed_proposals=%d days_used=%d unplaced_units=%d',
            count($proposals),
            count($digest['days_used']),
            count($digest['unplaced_units'])
        );
        $digest = $this->enrichTopNPlacementDigest(
            $digest,
            $proposals,
            $requestedCount,
            $scheduleOptions
        );

        if ($proposals === []) {
            $skipAdaptive = (bool) ($context['_refinement_skip_adaptive_fallback'] ?? false);
            if (
                ! $skipAdaptive
                && ! $adaptiveAttempted
                && $this->canUseAdaptiveFallbackForScheduleOptions($scheduleOptions)
                && $this->shouldAttemptAdaptiveFallback($snapshot, $context, $digest, $timezone)
            ) {
                $adaptiveContext = $context;
                $adaptiveContext['_adaptive_fallback_attempted'] = true;
                $adaptiveSnapshot = $this->buildAdaptiveFallbackSnapshot($snapshot, $timezone);
                [$retryProposals, $retryDigest] = $this->generateProposalsChunkedSpill($adaptiveSnapshot, $adaptiveContext, $countLimit, $scheduleOptions);

                if (! $this->isOnlyEmptyPlaceholderProposal($retryProposals)) {
                    $retryDigest['fallback_mode'] = 'auto_relaxed_today_or_tomorrow';
                    $retryDigest['fallback_trigger_reason'] = 'horizon_exhausted';

                    return [$retryProposals, $retryDigest];
                }
            }

            $digest = $this->applyPlacementDigestConsumerFlags($digest, [], $countLimit, $scheduleOptions);

            return [[], $digest];
        }

        foreach ($proposals as $idx => &$proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            $proposal['display_order'] = $idx;
        }
        unset($proposal);

        // Ensure blocks/items are time-ordered for consistent narrative and UI.
        usort($proposals, function (array $a, array $b): int {
            $aStart = $a['start_datetime'] ?? null;
            $bStart = $b['start_datetime'] ?? null;
            if (! is_string($aStart) || trim($aStart) === '' || ! is_string($bStart) || trim($bStart) === '') {
                return 0;
            }
            try {
                $ad = new \DateTimeImmutable($aStart);
                $bd = new \DateTimeImmutable($bStart);

                return $ad <=> $bd;
            } catch (\Throwable) {
                return 0;
            }
        });
        foreach ($proposals as $idx => &$proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            $proposal['display_order'] = $idx;
        }
        unset($proposal);

        $digest = $this->applyPlacementDigestConsumerFlags($digest, $proposals, $countLimit, $scheduleOptions);

        return [$proposals, $digest];
    }

    /**
     * When the planner scans the full backlog, {@see $digest} may list many `horizon_exhausted` rows even
     * though the user only asked to place one block (or we under-filled {@see $countLimit}). Suppress the
     * bulk "planning horizon" consumer paragraph in that case; keep it when a full batch was placed or
     * unplaced rows reflect an explicit count cap.
     *
     * @param  list<array<string, mixed>>  $proposals
     * @param  array<string, mixed>  $digest
     * @param  array<string, mixed>  $scheduleOptions
     * @return array<string, mixed>
     */
    private function applyPlacementDigestConsumerFlags(array $digest, array $proposals, int $countLimit, array $scheduleOptions): array
    {
        $digest['suppress_bulk_unplaced_narrative'] = $this->shouldSuppressBulkUnplacedNarrative(
            $proposals,
            $digest,
            $countLimit,
            $scheduleOptions
        );

        return $digest;
    }

    /**
     * @param  list<array<string, mixed>>  $proposals
     * @param  array<string, mixed>  $digest
     * @param  array<string, mixed>  $scheduleOptions
     */
    private function shouldSuppressBulkUnplacedNarrative(array $proposals, array $digest, int $countLimit, array $scheduleOptions): bool
    {
        $unplaced = is_array($digest['unplaced_units'] ?? null) ? $digest['unplaced_units'] : [];
        if ($unplaced === []) {
            return false;
        }

        $placedCount = count($proposals);
        if ($placedCount === 0) {
            return false;
        }

        foreach ($unplaced as $unit) {
            if (! is_array($unit)) {
                continue;
            }
            if ((string) ($unit['reason'] ?? '') === 'count_limit') {
                return false;
            }
        }

        foreach ($unplaced as $unit) {
            if (! is_array($unit)) {
                continue;
            }
            if ((string) ($unit['reason'] ?? '') !== 'horizon_exhausted') {
                return false;
            }
        }

        if ($this->hasSingleExplicitScheduleTarget($scheduleOptions)) {
            return true;
        }

        return $placedCount < $countLimit || $placedCount === 1;
    }

    /**
     * @param  array<string, mixed>  $scheduleOptions
     */
    private function hasSingleExplicitScheduleTarget(array $scheduleOptions): bool
    {
        $targets = is_array($scheduleOptions['target_entities'] ?? null) ? $scheduleOptions['target_entities'] : [];
        $real = 0;
        foreach ($targets as $target) {
            if (! is_array($target)) {
                continue;
            }
            if ((int) ($target['entity_id'] ?? 0) > 0) {
                $real++;
            }
        }

        return $real === 1;
    }

    /**
     * @param  array<string, mixed>  $scheduleOptions
     */
    private function resolveRequestedCount(int $countLimit, array $scheduleOptions): int
    {
        $requested = (int) ($scheduleOptions['count_limit'] ?? $countLimit);
        $explicitRequestedCount = (int) ($scheduleOptions['explicit_requested_count'] ?? 0);
        if ($explicitRequestedCount > 0) {
            $requested = $explicitRequestedCount;
        }

        return max(1, min($requested, 10));
    }

    /**
     * @param  array<string, mixed>  $scheduleOptions
     */
    private function resolveRequestedCountSource(array $scheduleOptions): string
    {
        $explicitRequestedCount = (int) ($scheduleOptions['explicit_requested_count'] ?? 0);

        return $explicitRequestedCount > 0 ? 'explicit_user' : 'system_default';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function resolveStrictRequestedDayFromSnapshot(array $snapshot): ?string
    {
        $horizon = is_array($snapshot['schedule_horizon'] ?? null) ? $snapshot['schedule_horizon'] : [];
        $label = (string) ($horizon['label'] ?? '');
        $mode = (string) ($horizon['mode'] ?? '');
        $startDate = trim((string) ($horizon['start_date'] ?? ''));
        $endDate = trim((string) ($horizon['end_date'] ?? ''));

        if ($mode !== 'single_day' || $startDate === '' || $startDate !== $endDate) {
            return null;
        }

        if (! str_starts_with($label, 'explicit_date_')
            && ! str_starts_with($label, 'relative_days_')
            && ! str_starts_with($label, 'qualified_weekday_')) {
            return null;
        }

        return $startDate;
    }

    /**
     * @param  array<string, mixed>  $unit
     * @param  array<string, mixed>  $scheduleOptions
     */
    private function canUsePartialPlacementForUnit(array $unit, array $scheduleOptions, int $requestedCount): bool
    {
        if ($requestedCount <= 1) {
            return true;
        }

        $policy = (string) config('task-assistant.schedule.partial_policy', 'top1_only');
        if ($policy !== 'top1_only') {
            return true;
        }

        $rank = (int) ($unit['priority_rank'] ?? PHP_INT_MAX);

        return $rank === 1;
    }

    /**
     * @param  array<string, mixed>  $digest
     * @param  list<array<string, mixed>>  $proposals
     * @param  array<string, mixed>  $scheduleOptions
     * @return array<string, mixed>
     */
    private function enrichTopNPlacementDigest(array $digest, array $proposals, int $requestedCount, array $scheduleOptions): array
    {
        $fullPlaced = 0;
        $partialPlaced = 0;
        $qualifiedPlaced = 0;

        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }

            $isPartial = (bool) ($proposal['partial'] ?? false);
            if ($isPartial) {
                $partialPlaced++;
            } else {
                $fullPlaced++;
            }

            if ($this->isProposalQualifiedForRequestedCount($proposal, $requestedCount)) {
                $qualifiedPlaced++;
            }
        }

        $digest['full_placed_count'] = $fullPlaced;
        $digest['partial_placed_count'] = $partialPlaced;

        $shortfallPolicy = (string) config('task-assistant.schedule.top_n_shortfall_policy', 'confirm_if_shortfall');
        $requestedCountSource = (string) ($digest['requested_count_source'] ?? $this->resolveRequestedCountSource($scheduleOptions));
        $isTopNContract = $shortfallPolicy === 'confirm_if_shortfall'
            && $requestedCount > 1
            && $requestedCountSource === 'explicit_user';
        $shortfallCount = $isTopNContract ? max(0, $requestedCount - $qualifiedPlaced) : 0;

        $digest['count_shortfall'] = $shortfallCount;
        $digest['top_n_shortfall'] = $shortfallCount > 0;

        return $digest;
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function isProposalQualifiedForRequestedCount(array $proposal, int $requestedCount): bool
    {
        $isPartial = (bool) ($proposal['partial'] ?? false);
        if (! $isPartial) {
            return true;
        }

        if ($requestedCount <= 1) {
            return true;
        }

        $policy = (string) config('task-assistant.schedule.partial_policy', 'top1_only');
        if ($policy !== 'top1_only') {
            return true;
        }

        return (int) ($proposal['priority_rank'] ?? PHP_INT_MAX) === 1;
    }

    /**
     * @param  array<string, mixed>  $scheduleOptions
     */
    private function canUseAdaptiveFallbackForScheduleOptions(array $scheduleOptions): bool
    {
        $overflowStrategy = (string) config('task-assistant.schedule.overflow_strategy', 'require_confirm');
        $requestedCount = $this->resolveRequestedCount((int) ($scheduleOptions['count_limit'] ?? 1), $scheduleOptions);

        if ($overflowStrategy === 'require_confirm' && $requestedCount > 1) {
            return false;
        }

        return true;
    }

    private function shouldAttemptAdaptiveFallback(
        array $snapshot,
        array $context,
        array $digest,
        \DateTimeZone $timezone
    ): bool {
        if ((bool) ($context['time_window_strict'] ?? false)) {
            return false;
        }

        $today = (string) ($snapshot['today'] ?? now($timezone)->format('Y-m-d'));
        $horizon = is_array($snapshot['schedule_horizon'] ?? null) ? $snapshot['schedule_horizon'] : [];
        $mode = (string) ($horizon['mode'] ?? 'single_day');
        $start = (string) ($horizon['start_date'] ?? $today);
        $end = (string) ($horizon['end_date'] ?? $today);

        if ($mode !== 'single_day' || $start !== $today || $end !== $today) {
            return false;
        }

        $unplaced = is_array($digest['unplaced_units'] ?? null) ? $digest['unplaced_units'] : [];
        foreach ($unplaced as $row) {
            if (is_array($row) && (string) ($row['reason'] ?? '') === 'horizon_exhausted') {
                return true;
            }
        }

        return false;
    }

    private function buildAdaptiveFallbackSnapshot(array $snapshot, \DateTimeZone $timezone): array
    {
        $adaptive = $snapshot;
        $today = CarbonImmutable::parse((string) ($snapshot['today'] ?? now($timezone)->format('Y-m-d')), $timezone)->startOfDay();
        $tomorrow = $today->addDay();

        $adaptive['time_window'] = ['start' => '08:00', 'end' => '22:00'];
        $adaptive['schedule_horizon'] = [
            'mode' => 'single_day',
            'start_date' => $tomorrow->toDateString(),
            'end_date' => $tomorrow->toDateString(),
            'label' => 'adaptive_tomorrow',
        ];

        return $adaptive;
    }

    /**
     * Re-place one draft proposal using the same spill engine as initial scheduling (calendar busy + siblings + time window).
     *
     * @param  array<int, array<string, mixed>>  $workingProposals
     * @return array{
     *   ok: bool,
     *   merged_proposals: array<int, array<string, mixed>>,
     *   digest?: array<string, mixed>,
     *   error?: string
     * }
     */
    public function placeRefinementProposalViaSpill(
        User $user,
        string $userMessage,
        array $workingProposals,
        int $targetIndex,
        int $scheduleUserId,
        array $refinementDayOptions = [],
    ): array {
        if ($targetIndex < 0 || $targetIndex >= count($workingProposals)) {
            return ['ok' => false, 'merged_proposals' => $workingProposals, 'error' => 'invalid_target_index'];
        }

        $targetRow = $workingProposals[$targetIndex];
        if (! is_array($targetRow)) {
            return ['ok' => false, 'merged_proposals' => $workingProposals, 'error' => 'invalid_target_row'];
        }

        $unit = $this->refinementSchedulingUnitFromProposal($targetRow);
        if ($unit === null) {
            return ['ok' => false, 'merged_proposals' => $workingProposals, 'error' => 'invalid_scheduling_unit'];
        }

        $entityType = (string) ($targetRow['entity_type'] ?? '');
        $entityId = (int) ($targetRow['entity_id'] ?? 0);
        $targetEntities = [[
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'title' => (string) ($targetRow['title'] ?? ''),
        ]];

        $options = [
            'target_entities' => $targetEntities,
            'schedule_user_id' => $scheduleUserId,
            'refinement_anchor_date' => is_string($refinementDayOptions['refinement_anchor_date'] ?? null)
                ? (string) $refinementDayOptions['refinement_anchor_date']
                : null,
            'refinement_explicit_day_override' => is_string($refinementDayOptions['refinement_explicit_day_override'] ?? null)
                ? (string) $refinementDayOptions['refinement_explicit_day_override']
                : null,
        ];

        $built = $this->dbContextBuilder->buildForUser($user, $userMessage, $options);
        $contextualSnapshot = $this->applyContextToSnapshot($built['snapshot'], $built['context'], $options);
        $context = $built['context'];
        $context['_refinement_mode'] = true;
        $context['_refinement_disable_partial_fit'] = true;
        $context['_refinement_skip_adaptive_fallback'] = true;
        $context['_refinement_skip_morning_shortcut'] = true;

        $contextualSnapshot = $this->mergeDraftSiblingBusyIntoSnapshot(
            $contextualSnapshot,
            $workingProposals,
            $targetIndex,
        );

        [$spillProposals, $digest] = $this->generateProposalsChunkedSpillCore(
            $contextualSnapshot,
            $context,
            1,
            [$unit],
            $options,
        );

        if ($spillProposals === [] || $this->isOnlyEmptyPlaceholderProposal($spillProposals)) {
            return [
                'ok' => false,
                'merged_proposals' => $workingProposals,
                'digest' => $digest,
                'error' => 'no_fit',
            ];
        }

        $placed = $spillProposals[0];
        if (! is_array($placed)) {
            return ['ok' => false, 'merged_proposals' => $workingProposals, 'digest' => $digest, 'error' => 'placement_invalid'];
        }

        if ((string) ($placed['entity_type'] ?? '') !== $entityType || (int) ($placed['entity_id'] ?? 0) !== $entityId) {
            return ['ok' => false, 'merged_proposals' => $workingProposals, 'digest' => $digest, 'error' => 'placement_mismatch'];
        }

        $merged = $workingProposals;
        $merged[$targetIndex] = $this->mergePlacedTimesIntoDraftRow($targetRow, $placed);

        Log::info('task-assistant.refinement_spill', [
            'layer' => 'structured_generation',
            'user_id' => $user->id,
            'target_index' => $targetIndex,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'digest_summary' => $digest['summary'] ?? null,
        ]);

        return ['ok' => true, 'merged_proposals' => $merged, 'digest' => $digest];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function shouldSkipMorningShortcutForSnapshot(array $snapshot): bool
    {
        $tw = $snapshot['time_window'] ?? null;
        if (! is_array($tw)) {
            return false;
        }

        $start = trim((string) ($tw['start'] ?? ''));

        return $start !== '' && $start >= '12:00';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<int, array<string, mixed>>  $draftProposals
     * @return array<string, mixed>
     */
    private function mergeDraftSiblingBusyIntoSnapshot(array $snapshot, array $draftProposals, int $excludeIndex): array
    {
        $busy = is_array($snapshot['events_for_busy'] ?? null) ? $snapshot['events_for_busy'] : [];
        if (! is_array($busy)) {
            $busy = [];
        }

        $nextSyntheticId = -1;
        foreach ($busy as $ev) {
            if (! is_array($ev)) {
                continue;
            }
            $id = $ev['id'] ?? null;
            if (is_int($id) && $id <= $nextSyntheticId) {
                $nextSyntheticId = $id - 1;
            }
        }

        foreach ($draftProposals as $i => $p) {
            if ($i === $excludeIndex || ! is_array($p)) {
                continue;
            }
            if ((string) ($p['status'] ?? 'pending') !== 'pending') {
                continue;
            }
            $title = trim((string) ($p['title'] ?? ''));
            if ($title === 'No schedulable items found') {
                continue;
            }
            $startRaw = (string) ($p['start_datetime'] ?? '');
            $endRaw = (string) ($p['end_datetime'] ?? '');
            if ($startRaw === '' || $endRaw === '') {
                continue;
            }

            $busy[] = [
                'id' => $nextSyntheticId,
                'title' => 'draft_sibling: '.($title !== '' ? $title : 'block'),
                'starts_at' => $startRaw,
                'ends_at' => $endRaw,
            ];
            $nextSyntheticId--;
        }

        $snapshot['events'] = is_array($snapshot['events'] ?? null) ? $snapshot['events'] : [];
        $snapshot['events_for_busy'] = $busy;

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $draftRow
     * @param  array<string, mixed>  $placedProposal
     * @return array<string, mixed>
     */
    private function mergePlacedTimesIntoDraftRow(array $draftRow, array $placedProposal): array
    {
        $merged = $draftRow;
        $merged['start_datetime'] = $placedProposal['start_datetime'] ?? $draftRow['start_datetime'] ?? '';
        $merged['end_datetime'] = $placedProposal['end_datetime'] ?? $draftRow['end_datetime'] ?? '';
        if (array_key_exists('duration_minutes', $placedProposal)) {
            $merged['duration_minutes'] = $placedProposal['duration_minutes'];
        }
        if (isset($placedProposal['reason_score'])) {
            $merged['reason_score'] = $placedProposal['reason_score'];
        }

        unset($merged['apply_payload']);

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function refinementSchedulingUnitFromProposal(array $row): ?array
    {
        $entityType = (string) ($row['entity_type'] ?? '');
        $entityId = (int) ($row['entity_id'] ?? 0);
        if ($entityId <= 0 || ! in_array($entityType, ['task', 'event', 'project'], true)) {
            return null;
        }

        $title = (string) ($row['title'] ?? '');
        $minutes = max(1, (int) ($row['duration_minutes'] ?? 30));
        if ($entityType === 'event') {
            $startRaw = (string) ($row['start_datetime'] ?? '');
            $endRaw = (string) ($row['end_datetime'] ?? '');
            if ($startRaw !== '' && $endRaw !== '') {
                try {
                    $s = new \DateTimeImmutable($startRaw);
                    $e = new \DateTimeImmutable($endRaw);
                    $minutes = max(1, (int) (($e->getTimestamp() - $s->getTimestamp()) / 60));
                } catch (\Throwable) {
                    // keep draft duration
                }
            }
        }

        $applyAs = $row['schedule_apply_as'] ?? null;
        $unit = [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'title' => $title !== '' ? $title : 'Item',
            'score' => 999_999,
            'minutes' => $minutes,
            'candidate_order' => 0,
            'priority_rank' => (int) ($row['priority_rank'] ?? 1),
        ];
        if (is_string($applyAs) && $applyAs !== '') {
            $unit['_refinement_schedule_apply_as'] = $applyAs;
        }

        return $unit;
    }

    /**
     * @param  array<string, mixed>  $unit
     * @param  list<string>  $placementDates
     * @param  array<string, array<int, array{start: \DateTimeImmutable, end: \DateTimeImmutable}>>  $windowsByDay
     * @param  array<int, array<string, mixed>>  $proposals
     * @param  array<int, int>  $taskPlacedChunks
     * @param  array<string, mixed>  $digest
     * @return array{
     *   placed: bool,
     *   proposals: array<int, array<string, mixed>>,
     *   windowsByDay: array<string, array<int, array{start: \DateTimeImmutable, end: \DateTimeImmutable}>>,
     *   taskPlacedChunks: array<int, int>,
     *   digest: array<string, mixed>
     * }
     */
    private function tryPlaceTopTaskInMorning(
        array $unit,
        array $placementDates,
        array $windowsByDay,
        array $proposals,
        array $taskPlacedChunks,
        array $digest
    ): array {
        $requestedMinutes = max(1, (int) ($unit['minutes'] ?? 30));
        $minimumKeepMinutes = max(30, (int) ceil($requestedMinutes * 0.80));

        foreach ($placementDates as $day) {
            $dayWindows = $windowsByDay[$day] ?? [];
            if ($dayWindows === []) {
                continue;
            }

            $fit = $this->findFirstMorningFittingWindow($dayWindows, $day, $requestedMinutes);
            $placedMinutes = $requestedMinutes;
            if ($fit === null) {
                $maxMorningMinutes = $this->maxMorningWindowMinutes($dayWindows, $day);
                if ($maxMorningMinutes < $minimumKeepMinutes) {
                    continue;
                }
                $placedMinutes = min($requestedMinutes, $maxMorningMinutes);
                $fit = $this->findFirstMorningFittingWindow($dayWindows, $day, $placedMinutes);
                if ($fit === null) {
                    continue;
                }
            }

            $windowIndex = (int) ($fit['window_index'] ?? 0);
            $startAt = $fit['start'] ?? null;
            if (! $startAt instanceof \DateTimeImmutable) {
                continue;
            }
            $endAt = $startAt->modify("+{$placedMinutes} minutes");

            $candidate = [
                'entity_type' => 'task',
                'entity_id' => $unit['entity_id'],
                'title' => $unit['title'],
                'score' => $unit['score'],
                'duration_minutes' => $placedMinutes,
                'priority_rank' => (int) ($unit['priority_rank'] ?? 1),
                'schedule_apply_as' => 'update_task',
            ];

            $proposal = $this->makeProposal($candidate, $startAt, $endAt, $placedMinutes);
            if ($placedMinutes < $requestedMinutes) {
                $proposal['partial'] = true;
                $proposal['requested_minutes'] = $requestedMinutes;
                $proposal['placed_minutes'] = $placedMinutes;
                $proposal['placement_reason'] = 'top1_morning_shrink';
                $digest['partial_units'][] = [
                    'entity_type' => 'task',
                    'entity_id' => $unit['entity_id'],
                    'title' => $unit['title'],
                    'requested_minutes' => $requestedMinutes,
                    'placed_minutes' => $placedMinutes,
                    'reason' => 'top1_morning_shrink',
                ];
            }

            $proposals[] = $proposal;
            $taskPlacedChunks[(int) $unit['entity_id']] = 1;
            $windowsByDay[$day] = $this->consumeWindow($dayWindows, $windowIndex, $startAt, $endAt);
            if (! in_array($day, $digest['days_used'], true)) {
                $digest['days_used'][] = $day;
            }

            return [
                'placed' => true,
                'proposals' => $proposals,
                'windowsByDay' => $windowsByDay,
                'taskPlacedChunks' => $taskPlacedChunks,
                'digest' => $digest,
            ];
        }

        return [
            'placed' => false,
            'proposals' => $proposals,
            'windowsByDay' => $windowsByDay,
            'taskPlacedChunks' => $taskPlacedChunks,
            'digest' => $digest,
        ];
    }

    /**
     * @param  array<int, array{start: \DateTimeImmutable, end: \DateTimeImmutable}>  $windows
     * @return array<int, array{start: \DateTimeImmutable, end: \DateTimeImmutable}>
     */
    /**
     * @param  array<int, array{start: \DateTimeImmutable, end: \DateTimeImmutable}>  $windows
     * @return array{window_index: int, start: \DateTimeImmutable}|null
     */
    private function findFirstMorningFittingWindow(array $windows, string $day, int $requiredMinutes): ?array
    {
        if ($windows === []) {
            return null;
        }

        $tz = $windows[0]['start']->getTimezone();
        $morningStart = new \DateTimeImmutable($day.' 08:00:00', $tz);
        $morningEnd = new \DateTimeImmutable($day.' 12:00:00', $tz);

        foreach ($windows as $index => $window) {
            $start = $window['start'] > $morningStart ? $window['start'] : $morningStart;
            $end = $window['end'] < $morningEnd ? $window['end'] : $morningEnd;
            if ($end > $start) {
                $minutes = (int) (($end->getTimestamp() - $start->getTimestamp()) / 60);
                if ($minutes >= $requiredMinutes) {
                    return ['window_index' => $index, 'start' => $start];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{start: \DateTimeImmutable, end: \DateTimeImmutable}>  $windows
     */
    private function maxMorningWindowMinutes(array $windows, string $day): int
    {
        if ($windows === []) {
            return 0;
        }

        $tz = $windows[0]['start']->getTimezone();
        $morningStart = new \DateTimeImmutable($day.' 08:00:00', $tz);
        $morningEnd = new \DateTimeImmutable($day.' 12:00:00', $tz);

        $max = 0;
        foreach ($windows as $window) {
            $start = $window['start'] > $morningStart ? $window['start'] : $morningStart;
            $end = $window['end'] < $morningEnd ? $window['end'] : $morningEnd;
            if ($end <= $start) {
                continue;
            }
            $minutes = (int) (($end->getTimestamp() - $start->getTimestamp()) / 60);
            if ($minutes > $max) {
                $max = $minutes;
            }
        }

        return $max;
    }

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     */
    private function isOnlyEmptyPlaceholderProposal(array $proposals): bool
    {
        if (count($proposals) !== 1) {
            return false;
        }

        return trim((string) ($proposals[0]['title'] ?? '')) === 'No schedulable items found';
    }

    /**
     * Attempt to schedule a partial focus block for a too-long task unit.
     *
     * @param  array<string, mixed>  $unit
     * @param  list<string>  $placementDates
     * @param  array<string, array<int, array{start: \DateTimeImmutable, end: \DateTimeImmutable}>>  $windowsByDay
     * @param  array<int, array<string, mixed>>  $proposals
     * @param  array<int, int>  $taskPlacedChunks
     * @param  array<string, mixed>  $digest
     * @return array{placed: bool, proposals: array<int, array<string, mixed>>, windowsByDay: array<string, array<int, array{start: \DateTimeImmutable, end: \DateTimeImmutable}>>, digest: array<string, mixed>}
     */
    private function placePartialTaskUnit(
        array $unit,
        array $placementDates,
        array $windowsByDay,
        \DateTimeZone $timezone,
        array $proposals,
        int $countLimit,
        array $taskPlacedChunks,
        array $digest,
        ?\DateTimeImmutable $minStartAt,
    ): array {
        if (count($proposals) >= $countLimit) {
            return ['placed' => false, 'proposals' => $proposals, 'windowsByDay' => $windowsByDay, 'digest' => $digest];
        }

        $requestedMinutes = max(1, (int) ($unit['minutes'] ?? 30));
        $minimumPartialRatio = 0.60;
        $minimumAbsolutePartialMinutes = 30;
        $minPartialMinutes = max($minimumAbsolutePartialMinutes, (int) ceil($requestedMinutes * $minimumPartialRatio));
        $best = null;

        foreach ($placementDates as $day) {
            $freeWindows = $windowsByDay[$day] ?? [];
            if ($minStartAt instanceof \DateTimeImmutable) {
                $freeWindows = array_values(array_filter(
                    $freeWindows,
                    static fn (array $w): bool => ($w['start'] ?? null) instanceof \DateTimeImmutable
                        && ($w['start'] >= $minStartAt)
                ));
            }
            foreach ($freeWindows as $wIndex => $w) {
                $diff = (int) (($w['end']->getTimestamp() - $w['start']->getTimestamp()) / 60);
                if ($diff < $minPartialMinutes) {
                    continue;
                }
                if ($best === null || $diff > $best['maxMinutes']) {
                    $best = [
                        'day' => $day,
                        'window_index' => $wIndex,
                        'startAt' => $w['start'],
                        'maxMinutes' => $diff,
                    ];
                }
            }
        }

        if ($best === null) {
            return ['placed' => false, 'proposals' => $proposals, 'windowsByDay' => $windowsByDay, 'digest' => $digest];
        }

        $placedMinutes = min($requestedMinutes, (int) $best['maxMinutes']);
        if ($placedMinutes < $minPartialMinutes) {
            return ['placed' => false, 'proposals' => $proposals, 'windowsByDay' => $windowsByDay, 'digest' => $digest];
        }

        $startAt = $best['startAt'];
        $endAt = $startAt->modify("+{$placedMinutes} minutes");

        $candidate = [
            'entity_type' => 'task',
            'entity_id' => $unit['entity_id'],
            'title' => $unit['title'],
            'score' => $unit['score'],
            'duration_minutes' => $placedMinutes,
            'priority_rank' => (int) ($unit['priority_rank'] ?? 1),
        ];

        $tid = (int) $unit['entity_id'];
        $prior = $taskPlacedChunks[$tid] ?? 0;
        $candidate['schedule_apply_as'] = $prior === 0 ? 'update_task' : 'create_event';

        $proposal = $this->makeProposal($candidate, $startAt, $endAt, $placedMinutes);
        $proposal['partial'] = true;
        $proposal['requested_minutes'] = $requestedMinutes;
        $proposal['placed_minutes'] = $placedMinutes;
        $proposal['placement_reason'] = 'partial_fit';

        $proposals[] = $proposal;

        $freeWindows = $windowsByDay[$best['day']] ?? [];
        $windowsByDay[$best['day']] = $this->consumeWindow($freeWindows, (int) $best['window_index'], $startAt, $endAt);

        if (! in_array($best['day'], $digest['days_used'], true)) {
            $digest['days_used'][] = $best['day'];
        }

        $digest['partial_units'][] = [
            'entity_type' => 'task',
            'entity_id' => $unit['entity_id'],
            'title' => $unit['title'],
            'requested_minutes' => $requestedMinutes,
            'placed_minutes' => $placedMinutes,
            'reason' => 'partial_fit',
        ];

        return [
            'placed' => true,
            'proposals' => $proposals,
            'windowsByDay' => $windowsByDay,
            'digest' => $digest,
        ];
    }

    /**
     * @return list<string> Y-m-d dates in the snapshot timezone
     */
    private function resolvePlacementDates(array $snapshot, \DateTimeZone $timezone): array
    {
        $maxDays = max(1, (int) config('task-assistant.schedule.max_horizon_days', 14));
        $horizon = $snapshot['schedule_horizon'] ?? null;
        $fallbackDay = (string) ($snapshot['today'] ?? now($timezone)->format('Y-m-d'));

        if (is_array($horizon) && isset($horizon['start_date'])) {
            $start = CarbonImmutable::parse((string) $horizon['start_date'], $timezone)->startOfDay();
        } else {
            $start = CarbonImmutable::parse($fallbackDay, $timezone)->startOfDay();
        }

        if (is_array($horizon)
            && ($horizon['mode'] ?? '') === 'range'
            && isset($horizon['end_date'])
            && (string) $horizon['start_date'] !== (string) $horizon['end_date']) {
            $end = CarbonImmutable::parse((string) $horizon['end_date'], $timezone)->startOfDay();
            $cap = $start->copy()->addDays($maxDays - 1);
            if ($end->gt($cap)) {
                $end = $cap;
            }
        } else {
            $end = $start->copy()->addDays($maxDays - 1);
        }

        $out = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $out[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $out !== [] ? $out : [$fallbackDay];
    }

    private function expandCandidatesToSchedulingUnits(array $candidates): array
    {
        $units = [];
        foreach ($candidates as $order => $candidate) {
            if (($candidate['entity_type'] ?? '') === 'task') {
                // Atomic scheduling: respect the chosen duration as a single block.
                $mins = max(1, (int) ($candidate['duration_minutes'] ?? 60));
                $units[] = [
                    'entity_type' => 'task',
                    'entity_id' => (int) ($candidate['entity_id'] ?? 0),
                    'title' => (string) ($candidate['title'] ?? 'Task'),
                    'score' => (int) ($candidate['score'] ?? 0),
                    'minutes' => $mins,
                    'complexity' => is_string($candidate['complexity'] ?? null) ? (string) $candidate['complexity'] : null,
                    'candidate_order' => $order,
                    'priority_rank' => (int) ($candidate['priority_rank'] ?? ($order + 1)),
                ];

                continue;
            }

            $units[] = [
                'entity_type' => (string) ($candidate['entity_type'] ?? ''),
                'entity_id' => (int) ($candidate['entity_id'] ?? 0),
                'title' => (string) ($candidate['title'] ?? ''),
                'score' => (int) ($candidate['score'] ?? 0),
                'minutes' => max(1, (int) ($candidate['duration_minutes'] ?? 30)),
                'candidate_order' => $order,
                'priority_rank' => (int) ($candidate['priority_rank'] ?? ($order + 1)),
            ];
        }

        return $units;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function compareSchedulingUnits(array $a, array $b): int
    {
        $scoreCmp = ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        if ($scoreCmp !== 0) {
            return $scoreCmp;
        }
        $orderCmp = ($a['candidate_order'] ?? 0) <=> ($b['candidate_order'] ?? 0);
        if ($orderCmp !== 0) {
            return $orderCmp;
        }

        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function makeProposal(array $candidate, \DateTimeImmutable $startAt, \DateTimeImmutable $endAt, int $minutes): array
    {
        $proposalUuid = (string) Str::uuid();
        $proposal = [
            'proposal_id' => $proposalUuid,
            'proposal_uuid' => $proposalUuid,
            'status' => 'pending',
            'entity_type' => $candidate['entity_type'],
            'entity_id' => $candidate['entity_id'],
            'title' => $candidate['title'],
            'reason_score' => $candidate['score'],
            'reason_code_primary' => (string) ($candidate['reason_code_primary'] ?? 'fit_window'),
            'reason_codes_secondary' => array_values(array_filter(array_map(
                static fn (mixed $code): string => trim((string) $code),
                is_array($candidate['reason_codes_secondary'] ?? null) ? $candidate['reason_codes_secondary'] : []
            ), static fn (string $code): bool => $code !== '')),
            'explainability_facts' => array_values(array_filter(array_map(
                static fn (mixed $fact): array => is_array($fact) ? $fact : [],
                is_array($candidate['explainability_facts'] ?? null) ? $candidate['explainability_facts'] : []
            ), static fn (array $fact): bool => isset($fact['key']) && isset($fact['value']))),
            'narrative_anchor' => is_array($candidate['narrative_anchor'] ?? null) ? $candidate['narrative_anchor'] : [],
            'start_datetime' => $startAt->format(\DateTimeInterface::ATOM),
            'end_datetime' => $candidate['entity_type'] === 'project' ? null : $endAt->format(\DateTimeInterface::ATOM),
            'duration_minutes' => $candidate['entity_type'] === 'event' ? null : $minutes,
            'conflict_notes' => [],
            'schedule_apply_as' => $candidate['schedule_apply_as'] ?? null,
            'apply_payload' => $this->buildApplyPayload($candidate, $startAt, $endAt, $minutes),
            'priority_rank' => isset($candidate['priority_rank']) ? (int) $candidate['priority_rank'] : null,
        ];

        return $proposal;
    }

    private function emptyPlaceholderProposal(
        \DateTimeImmutable $dayStart,
        \DateTimeImmutable $anchorForFallback,
        bool $multiDayHorizon,
    ): array {
        $proposalUuid = (string) \Illuminate\Support\Str::uuid();
        $note = $multiDayHorizon
            ? 'No tasks/events/projects could fit within the selected date range without conflicts.'
            : 'No tasks/events/projects could fit into the selected day without conflicts.';

        return [
            'proposal_id' => $proposalUuid,
            'proposal_uuid' => $proposalUuid,
            'status' => 'pending',
            'entity_type' => 'task',
            'entity_id' => null,
            'title' => 'No schedulable items found',
            'reason_score' => 0,
            'reason_code_primary' => 'no_schedulable_items',
            'reason_codes_secondary' => ['window_conflict'],
            'explainability_facts' => [
                ['key' => 'horizon_mode', 'value' => $multiDayHorizon ? 'range' : 'daily'],
            ],
            'narrative_anchor' => [
                'title' => 'No schedulable items found',
            ],
            'start_datetime' => $anchorForFallback->format(\DateTimeInterface::ATOM),
            'end_datetime' => $anchorForFallback->modify('+30 minutes')->format(\DateTimeInterface::ATOM),
            'duration_minutes' => 30,
            'conflict_notes' => [$note],
            'apply_payload' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $contextualSnapshot
     */
    private function buildDeterministicSummary(array $context, array $contextualSnapshot): string
    {
        $horizon = $contextualSnapshot['schedule_horizon'] ?? null;
        $horizonPhrase = '';
        if (is_array($horizon) && isset($horizon['label'], $horizon['start_date'], $horizon['end_date'])) {
            if (($horizon['mode'] ?? '') === 'range' && $horizon['start_date'] !== $horizon['end_date']) {
                $horizonPhrase = ' across '.$horizon['start_date'].' to '.$horizon['end_date'];
            } elseif (($horizon['label'] ?? '') !== 'default_today') {
                $horizonPhrase = ' for '.$horizon['label'];
            }
        }

        $summary = 'A focused schedule with clear blocks'.$horizonPhrase.' to structure your time';

        if (! empty($context['priority_filters'])) {
            $summary .= ' for '.implode(', ', $context['priority_filters']).' priority tasks';
        }

        if (! empty($context['task_keywords'])) {
            $summary .= ' related to '.implode(', ', $context['task_keywords']);
        }

        return $summary.'.';
    }

    private function buildApplyPayload(array $candidate, \DateTimeImmutable $startAt, \DateTimeImmutable $endAt, int $minutes): array
    {
        if (($candidate['entity_type'] ?? '') === 'task' && ($candidate['schedule_apply_as'] ?? '') === 'create_event') {
            $taskId = (int) ($candidate['entity_id'] ?? 0);
            $title = (string) ($candidate['title'] ?? 'Focus block');
            $description = sprintf('Task assistant focus block linked to task #%d.', $taskId);

            return [
                'action' => 'create_event',
                'arguments' => [
                    'title' => $title,
                    'description' => $description,
                    'startDatetime' => $startAt->format(\DateTimeInterface::ATOM),
                    'endDatetime' => $endAt->format(\DateTimeInterface::ATOM),
                ],
            ];
        }

        if ($candidate['entity_type'] === 'task') {
            return [
                'action' => 'update_task',
                'arguments' => [
                    'taskId' => $candidate['entity_id'],
                    'updates' => [
                        [
                            'property' => 'startDatetime',
                            'value' => $startAt->format(\DateTimeInterface::ATOM),
                        ],
                        [
                            'property' => 'duration',
                            'value' => (string) $minutes,
                        ],
                    ],
                ],
            ];
        }

        if ($candidate['entity_type'] === 'event') {
            return [
                'action' => 'update_event',
                'arguments' => [
                    'eventId' => $candidate['entity_id'],
                    'updates' => [
                        [
                            'property' => 'startDatetime',
                            'value' => $startAt->format(\DateTimeInterface::ATOM),
                        ],
                        [
                            'property' => 'endDatetime',
                            'value' => $endAt->format(\DateTimeInterface::ATOM),
                        ],
                    ],
                ],
            ];
        }

        return [
            'action' => 'update_project',
            'arguments' => [
                'projectId' => $candidate['entity_id'],
                'updates' => [
                    [
                        'property' => 'startDatetime',
                        'value' => $startAt->format(\DateTimeInterface::ATOM),
                    ],
                ],
            ],
        ];
    }

    private function buildBusyRanges(array $snapshot, \DateTimeImmutable $dayStart, \DateTimeImmutable $dayEnd, \DateTimeZone $timezone): array
    {
        $ranges = [];

        $classBusyIntervals = is_array($snapshot['school_class_busy_intervals'] ?? null)
            ? $snapshot['school_class_busy_intervals']
            : [];

        foreach ($classBusyIntervals as $interval) {
            if (! is_array($interval)) {
                continue;
            }

            $start = $this->safeDateTime($interval['start'] ?? null, $timezone);
            $end = $this->safeDateTime($interval['end'] ?? null, $timezone);
            if ($start === null || $end === null || $end <= $start) {
                continue;
            }

            if ($end <= $dayStart || $start >= $dayEnd) {
                continue;
            }

            $ranges[] = [
                'start' => $start < $dayStart ? $dayStart : $start,
                'end' => $end > $dayEnd ? $dayEnd : $end,
            ];
        }

        $this->appendLunchBusyRange($ranges, $snapshot, $dayStart, $dayEnd, $timezone);

        $eventSource = $snapshot['events_for_busy'] ?? $snapshot['events'] ?? [];
        foreach ($eventSource as $event) {
            if (! is_array($event)) {
                continue;
            }

            $start = $this->safeDateTime($event['starts_at'] ?? null, $timezone);
            $end = $this->resolveEventEnd($event, $start, $dayStart, $dayEnd, $timezone);
            if ($start === null || $end === null || $end <= $start) {
                Log::debug('task-assistant.schedule.skipped_invalid_busy_event', [
                    'event_id' => $event['id'] ?? null,
                    'starts_at' => $event['starts_at'] ?? null,
                    'ends_at' => $event['ends_at'] ?? null,
                ]);

                continue;
            }

            if ($end < $dayStart || $start > $dayEnd) {
                continue;
            }

            $ranges[] = [
                'start' => $start < $dayStart ? $dayStart : $start,
                'end' => $end > $dayEnd ? $dayEnd : $end,
            ];
        }

        $taskSource = is_array($snapshot['tasks'] ?? null) ? $snapshot['tasks'] : [];
        foreach ($taskSource as $task) {
            if (! is_array($task)) {
                continue;
            }

            $taskStart = $this->safeDateTime($task['starts_at'] ?? null, $timezone);
            if (! $taskStart instanceof \DateTimeImmutable) {
                continue;
            }

            $taskEnd = $this->resolveTaskBusyEnd($task, $taskStart, $timezone);
            if (! $taskEnd instanceof \DateTimeImmutable || $taskEnd <= $taskStart) {
                continue;
            }

            if ($taskEnd <= $dayStart || $taskStart >= $dayEnd) {
                continue;
            }

            $ranges[] = [
                'start' => $taskStart < $dayStart ? $dayStart : $taskStart,
                'end' => $taskEnd > $dayEnd ? $dayEnd : $taskEnd,
            ];
        }

        usort($ranges, fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $ranges;
    }

    private function buildFreeWindows(array $busyRanges, \DateTimeImmutable $dayStart, \DateTimeImmutable $dayEnd): array
    {
        $windows = [];
        $cursor = $dayStart;

        foreach ($busyRanges as $range) {
            if ($range['start'] > $cursor) {
                $windows[] = ['start' => $cursor, 'end' => $range['start']];
            }
            if ($range['end'] > $cursor) {
                $cursor = $range['end'];
            }
        }

        if ($cursor < $dayEnd) {
            $windows[] = ['start' => $cursor, 'end' => $dayEnd];
        }

        return $windows;
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function resolveCandidateDurationMinutes(array $task): int
    {
        $d = (int) ($task['duration_minutes'] ?? 0);

        return $d > 0 ? $d : 60;
    }

    private function buildSchedulingCandidates(array $snapshot, array $context): array
    {
        $ranked = $this->prioritizationService->prioritizeFocus($snapshot, $context);
        $candidates = [];
        $skippedTargets = [];

        if ($ranked === []) {
            return ['candidates' => $candidates, 'skipped_targets' => $skippedTargets];
        }

        $includedIndex = 0;
        $rankedCount = count($ranked);

        foreach ($ranked as $rankedCandidate) {
            if (! is_array($rankedCandidate)) {
                continue;
            }

            $type = (string) ($rankedCandidate['type'] ?? '');
            $id = (int) ($rankedCandidate['id'] ?? 0);
            $title = (string) ($rankedCandidate['title'] ?? '');

            if ($id <= 0 || trim($title) === '') {
                continue;
            }

            $score = (int) max(1, $rankedCount - $includedIndex);
            $priorityRank = $includedIndex + 1;

            if ($type === 'task') {
                $raw = is_array($rankedCandidate['raw'] ?? null) ? $rankedCandidate['raw'] : [];
                if ((string) ($raw['status'] ?? '') === TaskStatus::Doing->value) {
                    continue;
                }
                $candidates[] = [
                    'entity_type' => 'task',
                    'entity_id' => $id,
                    'title' => $title,
                    'duration_minutes' => $this->resolveCandidateDurationMinutes($raw),
                    'complexity' => is_string($raw['complexity'] ?? null) ? (string) $raw['complexity'] : null,
                    'score' => $score,
                    'priority_rank' => $priorityRank,
                ];

                $includedIndex++;

                continue;
            }

            if ($type === 'event') {
                $raw = is_array($rankedCandidate['raw'] ?? null) ? $rankedCandidate['raw'] : [];
                $startsAt = $raw['starts_at'] ?? null;
                $endsAt = $raw['ends_at'] ?? null;

                // Preserve scheduler membership rule:
                // - events are schedulable only when they are not already fully timed.
                if (! empty($startsAt) && ! empty($endsAt)) {
                    $skippedTargets[] = [
                        'entity_type' => 'event',
                        'entity_id' => $id,
                        'title' => $title,
                        'reason' => 'event_already_timed',
                    ];

                    continue;
                }

                $candidates[] = [
                    'entity_type' => 'event',
                    'entity_id' => $id,
                    'title' => $title,
                    'duration_minutes' => 60,
                    'score' => $score,
                    'priority_rank' => $priorityRank,
                ];

                $includedIndex++;

                continue;
            }

            if ($type === 'project') {
                $raw = is_array($rankedCandidate['raw'] ?? null) ? $rankedCandidate['raw'] : [];
                $startAt = $raw['start_at'] ?? null;

                // Preserve scheduler membership rule:
                // - projects are schedulable only when they haven't started yet.
                if (! empty($startAt)) {
                    $skippedTargets[] = [
                        'entity_type' => 'project',
                        'entity_id' => $id,
                        'title' => $title,
                        'reason' => 'project_already_started',
                    ];

                    continue;
                }

                $candidates[] = [
                    'entity_type' => 'project',
                    'entity_id' => $id,
                    'title' => $title,
                    'duration_minutes' => 30,
                    'score' => $score,
                    'priority_rank' => $priorityRank,
                ];

                $includedIndex++;

                continue;
            }
        }

        return [
            'candidates' => $candidates,
            'skipped_targets' => $skippedTargets,
        ];
    }

    private function computeBetweenBlockGapMinutes(int $blockMinutes): int
    {
        // Mapping chosen to keep things consistent for the student:
        // - <=60 min => 15 min
        // - >=120 min => 30 min
        // - linear interpolation between 60..120, rounded to nearest integer
        if ($blockMinutes <= 60) {
            return 15;
        }

        if ($blockMinutes >= 120) {
            return 30;
        }

        $t = ($blockMinutes - 60) / 60; // 0..1
        $gap = 15 + ($t * 15); // 15..30

        return max(15, min(30, (int) round($gap)));
    }

    private function findFirstFittingWindow(array $windows, int $requiredMinutes): ?array
    {
        foreach ($windows as $index => $window) {
            $diff = (int) (($window['end']->getTimestamp() - $window['start']->getTimestamp()) / 60);
            if ($diff >= $requiredMinutes) {
                return [$index, $window['start']];
            }
        }

        return null;
    }

    private function consumeWindow(array $windows, int $windowIndex, \DateTimeImmutable $startAt, \DateTimeImmutable $endAt): array
    {
        $window = $windows[$windowIndex];
        unset($windows[$windowIndex]);
        $windows = array_values($windows);

        if ($startAt > $window['start']) {
            $windows[] = ['start' => $window['start'], 'end' => $startAt];
        }
        if ($endAt < $window['end']) {
            $windows[] = ['start' => $endAt, 'end' => $window['end']];
        }

        usort($windows, fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $windows;
    }

    private function safeDateTime(mixed $value, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, $timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function resolveEventEnd(
        array $event,
        ?\DateTimeImmutable $start,
        \DateTimeImmutable $dayStart,
        \DateTimeImmutable $dayEnd,
        \DateTimeZone $timezone
    ): ?\DateTimeImmutable {
        if (! $start instanceof \DateTimeImmutable) {
            return null;
        }

        $explicitEnd = $this->safeDateTime($event['ends_at'] ?? null, $timezone);
        if ($explicitEnd instanceof \DateTimeImmutable) {
            return $explicitEnd;
        }

        $isAllDay = (bool) ($event['all_day'] ?? false);
        if ($isAllDay) {
            return $dayEnd;
        }

        $fallbackMinutes = max(15, (int) config('task-assistant.schedule.event_fallback_duration_minutes', 60));

        return $start->modify("+{$fallbackMinutes} minutes");
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function resolveTaskBusyEnd(
        array $task,
        \DateTimeImmutable $taskStart,
        \DateTimeZone $timezone
    ): ?\DateTimeImmutable {
        $explicitEnd = $this->safeDateTime($task['ends_at'] ?? null, $timezone);
        if ($explicitEnd instanceof \DateTimeImmutable) {
            return $explicitEnd;
        }

        $durationMinutes = max(0, (int) ($task['duration_minutes'] ?? 0));
        if ($durationMinutes > 0) {
            return $taskStart->modify("+{$durationMinutes} minutes");
        }

        return null;
    }

    /**
     * @param  array<int, array{start: \DateTimeImmutable, end: \DateTimeImmutable}>  $ranges
     * @param  array<string, mixed>  $snapshot
     */
    private function appendLunchBusyRange(
        array &$ranges,
        array $snapshot,
        \DateTimeImmutable $dayStart,
        \DateTimeImmutable $dayEnd,
        \DateTimeZone $timezone
    ): void {
        $lunchConfig = config('task-assistant.schedule.lunch_block', []);
        $enabled = (bool) ($lunchConfig['enabled'] ?? true);
        $start = is_string($lunchConfig['start'] ?? null) ? (string) $lunchConfig['start'] : '12:00';
        $end = is_string($lunchConfig['end'] ?? null) ? (string) $lunchConfig['end'] : '13:00';

        $preferences = is_array($snapshot['schedule_preferences'] ?? null)
            ? $snapshot['schedule_preferences']
            : [];
        $prefLunch = is_array($preferences['lunch_block'] ?? null) ? $preferences['lunch_block'] : null;
        if (is_array($prefLunch)) {
            $enabled = (bool) ($prefLunch['enabled'] ?? $enabled);
            $start = is_string($prefLunch['start'] ?? null) ? (string) $prefLunch['start'] : $start;
            $end = is_string($prefLunch['end'] ?? null) ? (string) $prefLunch['end'] : $end;
        }

        if (! $enabled) {
            return;
        }

        $lunchStart = $this->safeDateTime($dayStart->format('Y-m-d').' '.$start.':00', $timezone);
        $lunchEnd = $this->safeDateTime($dayStart->format('Y-m-d').' '.$end.':00', $timezone);
        if (! $lunchStart instanceof \DateTimeImmutable || ! $lunchEnd instanceof \DateTimeImmutable || $lunchEnd <= $lunchStart) {
            return;
        }

        if ($lunchEnd > $dayStart && $lunchStart < $dayEnd) {
            $ranges[] = [
                'start' => $lunchStart < $dayStart ? $dayStart : $lunchStart,
                'end' => $lunchEnd > $dayEnd ? $dayEnd : $lunchEnd,
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     */
    private function isScheduleEmptyPlacement(array $proposals): bool
    {
        if ($proposals === []) {
            return true;
        }

        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            if (trim((string) ($proposal['title'] ?? '')) === 'No schedulable items found') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     */
    private function countSchedulableProposals(array $proposals): int
    {
        $n = 0;
        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            if (trim((string) ($proposal['title'] ?? '')) === 'No schedulable items found') {
                continue;
            }
            if (($proposal['apply_payload'] ?? null) === null) {
                continue;
            }
            $n++;
        }

        return $n;
    }

    /**
     * Server-built view of scheduled rows for UI / validation (canonical times match proposals).
     *
     * @param  array<int, array<string, mixed>>  $proposals
     * @return list<array{
     *   title: string,
     *   entity_type: string,
     *   entity_id: int|null,
     *   start_datetime: string,
     *   end_datetime: string|null,
     *   duration_minutes: int|null
     * }>
     */
    private function buildScheduleItemsFromProposals(array $proposals): array
    {
        $items = [];
        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            $entityId = $proposal['entity_id'] ?? null;
            $items[] = [
                'title' => (string) ($proposal['title'] ?? ''),
                'entity_type' => (string) ($proposal['entity_type'] ?? ''),
                'entity_id' => $entityId !== null && $entityId !== '' ? (int) $entityId : null,
                'start_datetime' => (string) ($proposal['start_datetime'] ?? ''),
                'end_datetime' => isset($proposal['end_datetime']) && $proposal['end_datetime'] !== null && $proposal['end_datetime'] !== ''
                    ? (string) $proposal['end_datetime']
                    : null,
                'duration_minutes' => isset($proposal['duration_minutes']) && $proposal['duration_minutes'] !== null
                    ? (int) $proposal['duration_minutes']
                    : null,
            ];
        }

        return $items;
    }

    private function buildLegacyBlocksFromProposals(array $proposals, string $timezoneName): array
    {
        try {
            $tz = new \DateTimeZone($timezoneName !== '' ? $timezoneName : 'UTC');
        } catch (\Throwable) {
            $tz = new \DateTimeZone('UTC');
        }

        $blocks = [];
        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }

            $startAt = (string) ($proposal['start_datetime'] ?? '');
            $endAt = (string) ($proposal['end_datetime'] ?? '');
            $start = '00:00';
            $end = '00:00';
            if ($startAt !== '') {
                try {
                    $start = (new \DateTimeImmutable($startAt))->setTimezone($tz)->format('H:i');
                } catch (\Throwable) {
                    $start = '00:00';
                }
            }
            if ($endAt !== '') {
                try {
                    $end = (new \DateTimeImmutable($endAt))->setTimezone($tz)->format('H:i');
                } catch (\Throwable) {
                    $end = $start;
                }
            } else {
                $end = $start;
            }

            $blocks[] = [
                'start_time' => $start,
                'end_time' => $end,
                'task_id' => ($proposal['entity_type'] ?? null) === 'task' ? $proposal['entity_id'] : null,
                'event_id' => ($proposal['entity_type'] ?? null) === 'event' ? $proposal['entity_id'] : null,
                'label' => (string) ($proposal['title'] ?? 'Scheduled item'),
                'note' => 'Planned by strict scheduler.',
            ];
        }

        return $blocks;
    }
}
