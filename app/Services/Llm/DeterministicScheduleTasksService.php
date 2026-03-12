<?php

namespace App\Services\Llm;

use Carbon\CarbonImmutable;

class DeterministicScheduleTasksService
{
    /**
     * Build a deterministic multi-task schedule from Context.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function buildStructured(array $context): array
    {
        $timezone = is_string($context['timezone'] ?? null) && trim((string) $context['timezone']) !== ''
            ? (string) $context['timezone']
            : config('app.timezone', 'Asia/Manila');

        $now = $this->nowFromContext($context, $timezone);

        $windowStart = $this->parseWindowBoundary($context['requested_window_start'] ?? null, $timezone);
        $windowEnd = $this->parseWindowBoundary($context['requested_window_end'] ?? null, $timezone);

        if (! $windowStart instanceof CarbonImmutable || ! $windowEnd instanceof CarbonImmutable || $windowEnd->lte($windowStart)) {
            return [
                'entity_type' => 'task',
                'recommended_action' => __('I couldn’t find a valid time window to schedule your tasks.'),
                'reasoning' => __('The requested time window could not be parsed, so I couldn’t safely build a schedule.'),
                'scheduled_tasks' => [],
            ];
        }

        // Ensure we never suggest times in the past.
        $cursor = $windowStart->gt($now) ? $windowStart : $now->addMinute();

        $capMinutes = isset($context['focused_work_cap_minutes']) && is_numeric($context['focused_work_cap_minutes'])
            ? (int) $context['focused_work_cap_minutes']
            : 180;
        $capMinutes = max(1, $capMinutes);

        $busyWindows = $this->busyWindowsForDate($context, $windowStart->toDateString(), $timezone);

        $tasks = $this->tasksFromContext($context);
        if ($tasks === []) {
            return [
                'entity_type' => 'task',
                'recommended_action' => __('You have no tasks yet. Add tasks to your list to get scheduling suggestions.'),
                'reasoning' => __('I checked your tasks and there are none to schedule right now.'),
                'scheduled_tasks' => [],
            ];
        }

        $sorted = $this->sortTasksDueSoonThenPriority($tasks, $now, $timezone);

        $scheduled = [];
        $focusedMinutesScheduled = 0;
        $breakInserted = false;

        foreach ($sorted as $task) {
            if ($focusedMinutesScheduled >= $capMinutes) {
                break;
            }

            $remaining = $capMinutes - $focusedMinutesScheduled;
            $duration = isset($task['duration']) && is_numeric($task['duration']) ? (int) $task['duration'] : null;
            $duration = $duration !== null && $duration > 0 ? $duration : min(45, $remaining);

            // If it doesn't fit in remaining cap, allow partial as long as it's meaningful.
            if ($duration > $remaining) {
                $duration = $remaining;
            }
            if ($duration < 15) {
                continue;
            }

            $candidateStart = $this->nextFreeStart($cursor, $duration, $busyWindows, $windowEnd);
            if ($candidateStart === null) {
                break;
            }

            $candidateEnd = $candidateStart->addMinutes($duration);
            if ($candidateEnd->gt($windowEnd)) {
                break;
            }

            $scheduled[] = [
                'id' => (int) $task['id'],
                'title' => (string) $task['title'],
                'start_datetime' => $candidateStart->toIso8601String(),
                'duration' => $duration,
            ];

            $focusedMinutesScheduled += $duration;
            $cursor = $candidateEnd;

            // Insert one break (not represented in structured output) when there is room.
            if (! $breakInserted && $cursor->addMinutes(15)->lt($windowEnd)) {
                $cursor = $cursor->addMinutes(15);
                $breakInserted = true;
            }

            if (count($scheduled) >= 4) {
                break;
            }
        }

        $recommendedAction = $this->buildRecommendedAction($scheduled, $windowStart, $windowEnd, $timezone, $breakInserted);
        $reasoning = $scheduled === []
            ? __('I couldn’t fit any tasks into the requested window while respecting your constraints.')
            : __('I selected tasks that are due soon and fit them into your requested window while respecting your focused-work cap.');

        return [
            'entity_type' => 'task',
            'recommended_action' => $recommendedAction,
            'reasoning' => $reasoning,
            'scheduled_tasks' => $scheduled,
        ];
    }

    private function buildRecommendedAction(
        array $scheduled,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
        string $timezone,
        bool $breakInserted
    ): string {
        $header = __('Here’s a realistic plan from :start to :end:', [
            'start' => $windowStart->setTimezone($timezone)->format('g:ia'),
            'end' => $windowEnd->setTimezone($timezone)->format('g:ia'),
        ]);

        if ($scheduled === []) {
            return $header."\n\n".__('No tasks could be scheduled in that window.');
        }

        $lines = [];
        foreach ($scheduled as $i => $item) {
            $start = CarbonImmutable::parse($item['start_datetime'], $timezone);
            $end = $start->addMinutes((int) $item['duration']);
            $lines[] = sprintf(
                '- %s–%s — %s (%d min)',
                $start->format('g:ia'),
                $end->format('g:ia'),
                (string) $item['title'],
                (int) $item['duration']
            );

            if ($breakInserted && $i === 0) {
                $lines[] = '- '.__('Break (15 min)');
            }
        }

        return $header."\n".implode("\n", $lines);
    }

    private function nowFromContext(array $context, string $timezone): CarbonImmutable
    {
        $currentTime = $context['current_time'] ?? null;
        if (is_string($currentTime) && trim($currentTime) !== '') {
            try {
                return CarbonImmutable::parse($currentTime, $timezone)->setTimezone($timezone);
            } catch (\Throwable) {
                // fall through
            }
        }

        return CarbonImmutable::now($timezone);
    }

    private function parseWindowBoundary(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, $timezone)->setTimezone($timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, array{id:int,title:string,duration?:int,end_datetime?:string,priority?:string}>
     */
    private function tasksFromContext(array $context): array
    {
        $tasks = $context['tasks'] ?? null;
        if (! is_array($tasks) || $tasks === []) {
            return [];
        }

        $out = [];
        foreach ($tasks as $t) {
            if (! is_array($t) || ! isset($t['id'], $t['title']) || ! is_numeric($t['id'])) {
                continue;
            }
            $title = trim((string) $t['title']);
            if ($title === '') {
                continue;
            }
            $out[] = [
                'id' => (int) $t['id'],
                'title' => $title,
                'duration' => isset($t['duration']) && is_numeric($t['duration']) ? (int) $t['duration'] : null,
                'end_datetime' => isset($t['end_datetime']) && is_string($t['end_datetime']) ? $t['end_datetime'] : null,
                'priority' => isset($t['priority']) && is_string($t['priority']) ? $t['priority'] : null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, array{id:int,title:string,duration?:int,end_datetime?:string,priority?:string}>  $tasks
     * @return array<int, array{id:int,title:string,duration?:int,end_datetime?:string,priority?:string}>
     */
    private function sortTasksDueSoonThenPriority(array $tasks, CarbonImmutable $now, string $timezone): array
    {
        $nowPlus3Days = $now->addDays(3)->endOfDay();

        usort($tasks, static function (array $a, array $b) use ($nowPlus3Days, $timezone): int {
            $parseEnd = static function (array $t) use ($timezone): ?CarbonImmutable {
                $raw = $t['end_datetime'] ?? null;
                if (! is_string($raw) || trim($raw) === '') {
                    return null;
                }
                try {
                    return CarbonImmutable::parse($raw, $timezone);
                } catch (\Throwable) {
                    return null;
                }
            };

            $aEnd = $parseEnd($a);
            $bEnd = $parseEnd($b);

            $aDueSoon = $aEnd !== null && $aEnd->lte($nowPlus3Days);
            $bDueSoon = $bEnd !== null && $bEnd->lte($nowPlus3Days);

            if ($aDueSoon !== $bDueSoon) {
                return $aDueSoon ? -1 : 1;
            }

            if ($aEnd !== null && $bEnd !== null && ! $aEnd->eq($bEnd)) {
                return $aEnd->lt($bEnd) ? -1 : 1;
            }

            $weight = static function (?string $p): int {
                $p = $p !== null ? mb_strtolower(trim($p)) : '';

                return match ($p) {
                    'urgent' => 1,
                    'high' => 2,
                    'medium' => 3,
                    'low' => 4,
                    default => 5,
                };
            };

            $aW = $weight($a['priority'] ?? null);
            $bW = $weight($b['priority'] ?? null);
            if ($aW !== $bW) {
                return $aW <=> $bW;
            }

            return strcmp((string) $a['title'], (string) $b['title']);
        });

        return $tasks;
    }

    /**
     * @return array<int, array{start:CarbonImmutable,end:CarbonImmutable}>
     */
    private function busyWindowsForDate(array $context, string $date, string $timezone): array
    {
        $availability = $context['availability'] ?? null;
        if (! is_array($availability) || $availability === []) {
            return [];
        }

        $windows = [];
        foreach ($availability as $day) {
            if (! is_array($day) || (string) ($day['date'] ?? '') !== $date) {
                continue;
            }

            $busy = $day['busy_windows'] ?? null;
            if (! is_array($busy)) {
                continue;
            }

            foreach ($busy as $w) {
                if (! is_array($w)) {
                    continue;
                }
                $s = $w['start'] ?? null;
                $e = $w['end'] ?? null;
                if (! is_string($s) || trim($s) === '' || ! is_string($e) || trim($e) === '') {
                    continue;
                }
                try {
                    $start = CarbonImmutable::parse($s, $timezone);
                    $end = CarbonImmutable::parse($e, $timezone);
                    if ($end->gt($start)) {
                        $windows[] = ['start' => $start, 'end' => $end];
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        usort($windows, static fn (array $a, array $b): int => $a['start']->lt($b['start']) ? -1 : 1);

        return $windows;
    }

    /**
     * Find the next start time >= cursor where [start, start+duration) does not overlap busy windows.
     *
     * @param  array<int, array{start:CarbonImmutable,end:CarbonImmutable}>  $busyWindows
     */
    private function nextFreeStart(
        CarbonImmutable $cursor,
        int $durationMinutes,
        array $busyWindows,
        CarbonImmutable $windowEnd
    ): ?CarbonImmutable {
        $candidate = $cursor;

        while ($candidate->addMinutes($durationMinutes)->lte($windowEnd)) {
            $overlap = null;
            foreach ($busyWindows as $w) {
                $wStart = $w['start'];
                $wEnd = $w['end'];

                $end = $candidate->addMinutes($durationMinutes);
                $overlaps = $candidate->lt($wEnd) && $end->gt($wStart);
                if ($overlaps) {
                    $overlap = $w;
                    break;
                }
            }

            if ($overlap === null) {
                return $candidate;
            }

            $candidate = $overlap['end'];
        }

        return null;
    }
}
