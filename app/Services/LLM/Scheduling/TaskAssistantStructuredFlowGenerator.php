<?php

namespace App\Services\LLM\Scheduling;

use App\Models\TaskAssistantThread;
use App\Services\LLM\Prioritization\TaskPrioritizationService;
use App\Services\LLM\TaskAssistant\TaskAssistantHybridNarrativeService;
use App\Services\LLM\TaskAssistant\TaskAssistantPromptData;
use App\Services\LLM\TaskAssistant\TaskAssistantSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

final class TaskAssistantStructuredFlowGenerator
{
    public function __construct(
        private readonly TaskAssistantPromptData $promptData,
        private readonly TaskAssistantSnapshotService $snapshotService,
        private readonly TaskAssistantScheduleContextBuilder $scheduleContextBuilder,
        private readonly TaskAssistantHybridNarrativeService $hybridNarrative,
        private readonly TaskPrioritizationService $prioritizationService,
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
        $snapshot = $this->snapshotService->buildForUser($user);

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

        $context = $this->scheduleContextBuilder->build($userMessageContent, $snapshot);

        $contextualSnapshot = $this->applyContextToSnapshot($snapshot, $context, $options);
        $promptData['snapshot'] = $contextualSnapshot;
        $promptData['user_context'] = $context;
        $promptData['schedule_horizon'] = $contextualSnapshot['schedule_horizon'] ?? $context['schedule_horizon'] ?? null;

        $proposals = $this->generateDeterministicProposals($contextualSnapshot, $context);
        $blocks = $this->buildLegacyBlocksFromProposals($proposals);
        $deterministicSummary = $this->buildDeterministicSummary($context, $contextualSnapshot);

        $blocksJson = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $narrative = $this->hybridNarrative->refineDailySchedule(
            $historyMessages,
            $promptData,
            $userMessageContent,
            (string) $blocksJson,
            $deterministicSummary,
            $thread->id,
            $user->id,
        );

        $data = [
            'proposals' => $proposals,
            'blocks' => $blocks,
            'summary' => $narrative['summary'],
            'assistant_note' => $narrative['assistant_note'],
            'reasoning' => $narrative['reasoning'],
            'strategy_points' => $narrative['strategy_points'],
            'suggested_next_steps' => $narrative['suggested_next_steps'],
            'assumptions' => $narrative['assumptions'],
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
     * Apply context filtering to snapshot for scheduling.
     *
     * @return array<string, mixed>
     */
    private function applyContextToSnapshot(array $snapshot, array $context, array $options = []): array
    {
        $contextualSnapshot = $snapshot;
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
            $filtered = collect($snapshot['tasks'] ?? [])
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

        if (($context['time_constraint'] ?? null) === 'today') {
            $today = new \DateTime;
            $contextualSnapshot['tasks'] = collect($contextualSnapshot['tasks'] ?? [])
                ->filter(function (array $task) use ($today): bool {
                    if (! isset($task['ends_at']) || $task['ends_at'] === null) {
                        return false;
                    }

                    try {
                        $deadline = new \DateTime($task['ends_at']);

                        return $deadline->format('Y-m-d') === $today->format('Y-m-d');
                    } catch (\Exception $e) {
                        return false;
                    }
                })
                ->values()
                ->all();
            if ($contextualSnapshot['tasks'] === []) {
                $contextualSnapshot['tasks'] = $snapshot['tasks'] ?? [];
            }
        }

        if (($context['time_constraint'] ?? null) === 'this_week') {
            $timezone = (string) ($snapshot['timezone'] ?? config('app.timezone', 'UTC'));
            $now = CarbonImmutable::now($timezone);
            $tasksBefore = $contextualSnapshot['tasks'] ?? [];
            $filtered = $this->prioritizationService->filterTasksForTimeConstraint($tasksBefore, 'this_week', $now);
            $contextualSnapshot['tasks'] = $filtered;
            if ($contextualSnapshot['tasks'] === []) {
                $contextualSnapshot['tasks'] = $tasksBefore;
            }
        }

        $timeWindowHint = $options['time_window_hint'] ?? null;
        if ($timeWindowHint === 'later_afternoon') {
            $contextualSnapshot['time_window'] = ['start' => '15:00', 'end' => '18:00'];
        } elseif ($timeWindowHint === 'morning') {
            $contextualSnapshot['time_window'] = ['start' => '08:00', 'end' => '12:00'];
        } elseif ($timeWindowHint === 'evening') {
            $contextualSnapshot['time_window'] = ['start' => '18:00', 'end' => '22:00'];
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
     * @return array<int, array<string, mixed>>
     */
    private function generateDeterministicProposals(array $snapshot, array $context): array
    {
        $horizon = $snapshot['schedule_horizon'] ?? null;
        if (is_array($horizon)
            && ($horizon['mode'] ?? '') === 'range'
            && isset($horizon['start_date'], $horizon['end_date'])
            && $horizon['start_date'] !== $horizon['end_date']) {
            return $this->generateProposalsAcrossHorizon($snapshot, $context, $horizon);
        }

        return $this->generateProposalsSingleDay($snapshot, $context);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generateProposalsSingleDay(array $snapshot, array $context): array
    {
        $timezone = new \DateTimeZone((string) ($snapshot['timezone'] ?? config('app.timezone', 'UTC')));
        $day = (string) ($snapshot['today'] ?? now($timezone)->format('Y-m-d'));
        $window = is_array($snapshot['time_window'] ?? null) ? $snapshot['time_window'] : null;
        $windowStart = is_string($window['start'] ?? null) ? $window['start'] : '00:00';
        $windowEnd = is_string($window['end'] ?? null) ? $window['end'] : '23:59:59';
        $dayStart = new \DateTimeImmutable($day.' '.$windowStart, $timezone);
        $dayEnd = new \DateTimeImmutable($day.' '.$windowEnd, $timezone);

        $busyRanges = $this->buildBusyRanges($snapshot, $dayStart, $dayEnd, $timezone);
        $freeWindows = $this->buildFreeWindows($busyRanges, $dayStart, $dayEnd);
        $candidates = $this->buildSchedulingCandidates($snapshot);

        usort($candidates, fn (array $a, array $b): int => ($b['score'] <=> $a['score']));

        $proposals = [];
        foreach ($candidates as $candidate) {
            $minutes = (int) ($candidate['duration_minutes'] ?? 30);
            $fitted = $this->findFirstFittingWindow($freeWindows, $minutes);
            if ($fitted === null) {
                continue;
            }

            [$windowIndex, $startAt] = $fitted;
            $endAt = $startAt->modify("+{$minutes} minutes");

            $proposals[] = $this->makeProposal($candidate, $startAt, $endAt, $minutes);
            $freeWindows = $this->consumeWindow($freeWindows, $windowIndex, $startAt, $endAt);
        }

        if ($proposals === []) {
            return [$this->emptyPlaceholderProposal($dayStart, $dayStart, false)];
        }

        return $proposals;
    }

    /**
     * @param  array{
     *   mode: string,
     *   start_date: string,
     *   end_date: string,
     *   label: string
     * }  $horizon
     * @return array<int, array<string, mixed>>
     */
    private function generateProposalsAcrossHorizon(array $snapshot, array $context, array $horizon): array
    {
        $timezone = new \DateTimeZone((string) ($snapshot['timezone'] ?? config('app.timezone', 'UTC')));
        $window = is_array($snapshot['time_window'] ?? null) ? $snapshot['time_window'] : null;
        $windowStart = is_string($window['start'] ?? null) ? $window['start'] : '00:00';
        $windowEnd = is_string($window['end'] ?? null) ? $window['end'] : '23:59:59';

        $start = CarbonImmutable::parse($horizon['start_date'], $timezone)->startOfDay();
        $end = CarbonImmutable::parse($horizon['end_date'], $timezone)->startOfDay();

        /** @var array<string, array<int, array{start: \DateTimeImmutable, end: \DateTimeImmutable}>> $windowsByDay */
        $windowsByDay = [];
        $dayCursor = $start->copy();
        while ($dayCursor->lte($end)) {
            $day = $dayCursor->toDateString();
            $dayStart = new \DateTimeImmutable($day.' '.$windowStart, $timezone);
            $dayEnd = new \DateTimeImmutable($day.' '.$windowEnd, $timezone);
            $busyRanges = $this->buildBusyRanges($snapshot, $dayStart, $dayEnd, $timezone);
            $windowsByDay[$day] = $this->buildFreeWindows($busyRanges, $dayStart, $dayEnd);
            $dayCursor = $dayCursor->addDay();
        }

        $candidates = $this->buildSchedulingCandidates($snapshot);
        usort($candidates, fn (array $a, array $b): int => ($b['score'] <=> $a['score']));

        $proposals = [];
        $scanCursor = $start->copy();
        foreach ($candidates as $candidate) {
            $minutes = (int) ($candidate['duration_minutes'] ?? 30);
            $scanCursor = $start->copy();
            while ($scanCursor->lte($end)) {
                $day = $scanCursor->toDateString();
                $freeWindows = $windowsByDay[$day] ?? [];
                $fitted = $this->findFirstFittingWindow($freeWindows, $minutes);
                if ($fitted === null) {
                    $scanCursor = $scanCursor->addDay();

                    continue;
                }

                [$windowIndex, $startAt] = $fitted;
                $endAt = $startAt->modify("+{$minutes} minutes");

                $proposals[] = $this->makeProposal($candidate, $startAt, $endAt, $minutes);
                $windowsByDay[$day] = $this->consumeWindow($freeWindows, $windowIndex, $startAt, $endAt);
                break;
            }
        }

        if ($proposals === []) {
            $fallbackStart = new \DateTimeImmutable($start->toDateString().' '.$windowStart, $timezone);

            return [$this->emptyPlaceholderProposal($fallbackStart, $fallbackStart, true)];
        }

        return $proposals;
    }

    /**
     * @return array<string, mixed>
     */
    private function makeProposal(array $candidate, \DateTimeImmutable $startAt, \DateTimeImmutable $endAt, int $minutes): array
    {
        return [
            'proposal_id' => (string) \Illuminate\Support\Str::uuid(),
            'status' => 'pending',
            'entity_type' => $candidate['entity_type'],
            'entity_id' => $candidate['entity_id'],
            'title' => $candidate['title'],
            'reason_score' => $candidate['score'],
            'start_datetime' => $startAt->format(\DateTimeInterface::ATOM),
            'end_datetime' => $candidate['entity_type'] === 'project' ? null : $endAt->format(\DateTimeInterface::ATOM),
            'duration_minutes' => $candidate['entity_type'] === 'event' ? null : $minutes,
            'conflict_notes' => [],
            'apply_payload' => $this->buildApplyPayload($candidate, $startAt, $endAt, $minutes),
        ];
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

        foreach (($snapshot['events'] ?? []) as $event) {
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

    private function buildSchedulingCandidates(array $snapshot): array
    {
        $priorityScore = ['urgent' => 100, 'high' => 70, 'medium' => 40, 'low' => 15];
        $candidates = [];

        foreach (($snapshot['tasks'] ?? []) as $task) {
            if (! is_array($task) || ! isset($task['id'])) {
                continue;
            }
            if (trim((string) ($task['title'] ?? '')) === '') {
                continue;
            }

            $score = $priorityScore[strtolower((string) ($task['priority'] ?? 'medium'))] ?? 30;
            if (is_string($task['ends_at'] ?? null) && $task['ends_at'] !== '') {
                $score += 20;
            }

            $candidates[] = [
                'entity_type' => 'task',
                'entity_id' => (int) $task['id'],
                'title' => (string) ($task['title'] ?? 'Task'),
                'duration_minutes' => max(30, (int) ($task['duration_minutes'] ?? 60)),
                'score' => $score,
            ];
        }

        foreach (($snapshot['events'] ?? []) as $event) {
            if (! is_array($event) || ! isset($event['id'])) {
                continue;
            }

            if (! empty($event['starts_at']) && ! empty($event['ends_at'])) {
                continue;
            }

            $candidates[] = [
                'entity_type' => 'event',
                'entity_id' => (int) $event['id'],
                'title' => (string) ($event['title'] ?? 'Event'),
                'duration_minutes' => 60,
                'score' => 50,
            ];
        }

        foreach (($snapshot['projects'] ?? []) as $project) {
            if (! is_array($project) || ! isset($project['id'])) {
                continue;
            }

            if (! empty($project['start_at'])) {
                continue;
            }

            $candidates[] = [
                'entity_type' => 'project',
                'entity_id' => (int) $project['id'],
                'title' => (string) ($project['name'] ?? 'Project'),
                'duration_minutes' => 30,
                'score' => 25,
            ];
        }

        return $candidates;
    }

    private function findFirstFittingWindow(array $windows, int $minutes): ?array
    {
        foreach ($windows as $index => $window) {
            $diff = (int) (($window['end']->getTimestamp() - $window['start']->getTimestamp()) / 60);
            if ($diff >= $minutes) {
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

    private function buildLegacyBlocksFromProposals(array $proposals): array
    {
        $blocks = [];
        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }

            $startAt = (string) ($proposal['start_datetime'] ?? '');
            $endAt = (string) ($proposal['end_datetime'] ?? '');
            $start = $startAt !== '' ? (new \DateTimeImmutable($startAt))->format('H:i') : '00:00';
            $end = $endAt !== '' ? (new \DateTimeImmutable($endAt))->format('H:i') : $start;

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
