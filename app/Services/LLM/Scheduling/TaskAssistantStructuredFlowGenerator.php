<?php

namespace App\Services\LLM\Scheduling;

use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Services\LLM\Prioritization\TaskPrioritizationService;
use App\Services\LLM\TaskAssistant\TaskAssistantHybridNarrativeService;
use App\Services\LLM\TaskAssistant\TaskAssistantPromptData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class TaskAssistantStructuredFlowGenerator
{
    public function __construct(
        private readonly TaskAssistantPromptData $promptData,
        private readonly TaskAssistantScheduleDbContextBuilder $dbContextBuilder,
        private readonly TaskAssistantScheduleContextBuilder $scheduleContextBuilder,
        private readonly TaskPrioritizationService $prioritizationService,
        private readonly TaskAssistantHybridNarrativeService $hybridNarrative,
    ) {}

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
        $context = $built['context'];
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
        $countLimit = max(1, min((int) ($options['count_limit'] ?? 10), 10));

        [$proposals, $placementDigest] = $this->generateProposalsChunkedSpill($contextualSnapshot, $context, $countLimit);
        $promptData['placement_digest'] = $placementDigest;
        $timezoneName = (string) ($contextualSnapshot['timezone'] ?? config('app.timezone', 'UTC'));
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

        $data = [
            'proposals' => $proposals,
            'blocks' => $blocks,
            'items' => $items,
            'schedule_variant' => $scheduleVariant,
            'framing' => $narrative['framing'],
            'reasoning' => $narrative['reasoning'],
            'confirmation' => $narrative['confirmation'],
            'schedule_empty_placement' => $isEmptyPlacement,
            'placement_digest' => $placementDigest,
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
        $contextualSnapshot = $this->applyContextToSnapshot($snapshot, $context, $options);

        return [$context, $contextualSnapshot];
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

        $promptData = $this->promptData->forUser($user);
        $promptData['snapshot'] = $contextualSnapshot;
        $promptData['user_context'] = $context;
        $promptData['schedule_horizon'] = $contextualSnapshot['schedule_horizon'] ?? $context['schedule_horizon'] ?? null;
        $promptData['placement_digest'] = $digestForPrompt;

        $proposals = $this->regenerateApplyPayloadsForProposals($proposals);
        $timezoneName = (string) ($contextualSnapshot['timezone'] ?? config('app.timezone', 'UTC'));
        $blocks = $this->buildLegacyBlocksFromProposals($proposals, $timezoneName);
        $items = $this->buildScheduleItemsFromProposals($proposals);
        $deterministicSummary = $this->buildDeterministicSummary($context, $contextualSnapshot);
        $blocksJson = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $isEmptyPlacement = $this->isScheduleEmptyPlacement($proposals);
        $schedulableProposalCount = $this->countSchedulableProposals($proposals);

        $digestForNarrative = ($digestJsonNorm !== '{}' && $digestJsonNorm !== '[]') ? $digestJsonNorm : null;

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

        $data = [
            'proposals' => $proposals,
            'blocks' => $blocks,
            'items' => $items,
            'schedule_variant' => $scheduleVariant,
            'framing' => $narrative['framing'],
            'reasoning' => $narrative['reasoning'],
            'confirmation' => $narrative['confirmation'],
            'schedule_empty_placement' => $isEmptyPlacement,
            'placement_digest' => $digestForPrompt,
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
            ->with(['tags', 'recurringTask'])
            ->forUser($userId)
            ->whereIn('id', $missing)
            ->get()
            ->keyBy(static fn (Task $task): int => $task->id);

        foreach ($missing as $mid) {
            $task = $fetched->get($mid);
            if ($task === null) {
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
                'teacher_name' => $task->teacher_name,
                'tags' => $task->tags->pluck('name')->values()->all(),
                'status' => $task->status?->value,
                'priority' => $task->priority?->value,
                'complexity' => $task->complexity?->value,
                'ends_at' => $task->end_datetime?->toIso8601String(),
                'project_id' => $task->project_id,
                'event_id' => $task->event_id,
                'duration_minutes' => $task->duration,
                'is_recurring' => $task->recurringTask !== null,
            ];
        }

        $contextualSnapshot['tasks'] = array_values($tasks);
        $contextualSnapshot['schedule_target_skips'] = array_values($skips);

        return $contextualSnapshot;
    }

    private function generateProposalsChunkedSpill(array $snapshot, array $context, int $countLimit): array
    {
        $timezone = new \DateTimeZone((string) ($snapshot['timezone'] ?? config('app.timezone', 'UTC')));
        $placementDates = array_slice($this->resolvePlacementDates($snapshot, $timezone), 0, 1);
        $window = is_array($snapshot['time_window'] ?? null) ? $snapshot['time_window'] : null;
        $windowStart = is_string($window['start'] ?? null) ? $window['start'] : '00:00';
        $windowEnd = is_string($window['end'] ?? null) ? $window['end'] : '23:59:59';

        /** @var array<string, array<int, array{start: \DateTimeImmutable, end: \DateTimeImmutable}>> $windowsByDay */
        $windowsByDay = [];
        foreach ($placementDates as $day) {
            $dayStart = new \DateTimeImmutable($day.' '.$windowStart, $timezone);
            $dayEnd = new \DateTimeImmutable($day.' '.$windowEnd, $timezone);
            $busyRanges = $this->buildBusyRanges($snapshot, $dayStart, $dayEnd, $timezone);
            $windowsByDay[$day] = $this->buildFreeWindows($busyRanges, $dayStart, $dayEnd);
        }

        $candidates = $this->buildSchedulingCandidates($snapshot, $context);
        usort($candidates, fn (array $a, array $b): int => ($b['score'] <=> $a['score']));

        $units = $this->expandCandidatesToSchedulingUnits($candidates);
        usort($units, fn (array $a, array $b): int => $this->compareSchedulingUnits($a, $b));

        $digest = [
            'placement_dates' => $placementDates,
            'days_used' => [],
            'skipped_targets' => is_array($snapshot['schedule_target_skips'] ?? null)
                ? $snapshot['schedule_target_skips']
                : [],
            'unplaced_units' => [],
            'partial_units' => [],
            'summary' => '',
        ];

        $proposals = [];
        $totalUnits = count($units);
        /** @var array<int, int> $taskPlacedChunks */
        $taskPlacedChunks = [];

        $anchorDay = $placementDates[0] ?? (string) ($snapshot['today'] ?? now($timezone)->format('Y-m-d'));
        $anchorStart = new \DateTimeImmutable($anchorDay.' '.$windowStart, $timezone);

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

                $fitted = $this->findFirstFittingWindow($freeWindows, $requiredMinutes);
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
                ];

                if ($unit['entity_type'] === 'task') {
                    $tid = (int) $unit['entity_id'];
                    $prior = $taskPlacedChunks[$tid] ?? 0;
                    $candidate['schedule_apply_as'] = $prior === 0 ? 'update_task' : 'create_event';
                    $taskPlacedChunks[$tid] = $prior + 1;
                }

                $proposals[] = $this->makeProposal($candidate, $startAt, $blockEndAt, $blockMinutes);
                $windowsByDay[$day] = $this->consumeWindow($freeWindows, $windowIndex, $startAt, $consumeEndAt);

                if (! in_array($day, $digest['days_used'], true)) {
                    $digest['days_used'][] = $day;
                }
                $placed = true;

                break;
            }

            if (! $placed) {
                // Partial placement fallback for tasks: if we can't fit the full duration,
                // still schedule a starter block so the top-ranked work is represented.
                if (($unit['entity_type'] ?? '') === 'task') {
                    $partial = $this->placePartialTaskUnit(
                        unit: $unit,
                        placementDates: $placementDates,
                        windowsByDay: $windowsByDay,
                        timezone: $timezone,
                        proposals: $proposals,
                        countLimit: $countLimit,
                        taskPlacedChunks: $taskPlacedChunks,
                        digest: $digest,
                    );
                    if ($partial['placed'] ?? false) {
                        $proposals = $partial['proposals'];
                        $windowsByDay = $partial['windowsByDay'];
                        $digest = $partial['digest'];
                        $placed = true;
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

        if ($proposals === []) {
            return [[$this->emptyPlaceholderProposal($anchorStart, $anchorStart, count($placementDates) > 1)], $digest];
        }

        return [$proposals, $digest];
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
                    'candidate_order' => $order,
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
        $proposal = [
            'proposal_id' => (string) Str::uuid(),
            'status' => 'pending',
            'entity_type' => $candidate['entity_type'],
            'entity_id' => $candidate['entity_id'],
            'title' => $candidate['title'],
            'reason_score' => $candidate['score'],
            'start_datetime' => $startAt->format(\DateTimeInterface::ATOM),
            'end_datetime' => $candidate['entity_type'] === 'project' ? null : $endAt->format(\DateTimeInterface::ATOM),
            'duration_minutes' => $candidate['entity_type'] === 'event' ? null : $minutes,
            'conflict_notes' => [],
            'schedule_apply_as' => $candidate['schedule_apply_as'] ?? null,
            'apply_payload' => $this->buildApplyPayload($candidate, $startAt, $endAt, $minutes),
        ];

        return $proposal;
    }

    private function emptyPlaceholderProposal(
        \DateTimeImmutable $dayStart,
        \DateTimeImmutable $anchorForFallback,
        bool $multiDayHorizon,
    ): array {
        $note = $multiDayHorizon
            ? 'No tasks/events/projects could fit within the selected date range without conflicts.'
            : 'No tasks/events/projects could fit into the selected day without conflicts.';

        return [
            'proposal_id' => (string) \Illuminate\Support\Str::uuid(),
            'status' => 'pending',
            'entity_type' => 'task',
            'entity_id' => null,
            'title' => 'No schedulable items found',
            'reason_score' => 0,
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
                'tool' => 'create_event',
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
                'tool' => 'update_task',
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
                'tool' => 'update_event',
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
            'tool' => 'update_project',
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

        // Built-in lunch break (product default): 12:00–13:00 local time.
        // Represented as a busy range so it naturally subtracts from free windows.
        $lunchStart = new \DateTimeImmutable($dayStart->format('Y-m-d').' 12:00:00', $timezone);
        $lunchEnd = new \DateTimeImmutable($dayStart->format('Y-m-d').' 13:00:00', $timezone);
        if ($lunchEnd > $lunchStart && $lunchEnd > $dayStart && $lunchStart < $dayEnd) {
            $ranges[] = [
                'start' => $lunchStart < $dayStart ? $dayStart : $lunchStart,
                'end' => $lunchEnd > $dayEnd ? $dayEnd : $lunchEnd,
            ];
        }

        $eventSource = $snapshot['events_for_busy'] ?? $snapshot['events'] ?? [];
        foreach ($eventSource as $event) {
            if (! is_array($event)) {
                continue;
            }

            $start = $this->safeDateTime($event['starts_at'] ?? null, $timezone);
            $end = $this->safeDateTime($event['ends_at'] ?? null, $timezone);
            if ($start === null || $end === null || $end <= $start) {
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

        if ($ranked === []) {
            return $candidates;
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

            if ($type === 'task') {
                $raw = is_array($rankedCandidate['raw'] ?? null) ? $rankedCandidate['raw'] : [];
                $candidates[] = [
                    'entity_type' => 'task',
                    'entity_id' => $id,
                    'title' => $title,
                    'duration_minutes' => $this->resolveCandidateDurationMinutes($raw),
                    'score' => $score,
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
                    continue;
                }

                $candidates[] = [
                    'entity_type' => 'event',
                    'entity_id' => $id,
                    'title' => $title,
                    'duration_minutes' => 60,
                    'score' => $score,
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
                    continue;
                }

                $candidates[] = [
                    'entity_type' => 'project',
                    'entity_id' => $id,
                    'title' => $title,
                    'duration_minutes' => 30,
                    'score' => $score,
                ];

                $includedIndex++;

                continue;
            }
        }

        return $candidates;
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
     * @param  array<int, array<string, mixed>>  $proposals
     */
    private function isScheduleEmptyPlacement(array $proposals): bool
    {
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
