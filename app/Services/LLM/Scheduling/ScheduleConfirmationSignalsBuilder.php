<?php

namespace App\Services\LLM\Scheduling;

/**
 * Builds {@see $placementDigest}['confirmation_signals'] for schedule UX policy and LLM facts.
 * Placement truth remains in the generator; this only summarizes scope vs actuals.
 */
final class ScheduleConfirmationSignalsBuilder
{
    /**
     * @param  array<string, mixed>  $snapshot  Contextual snapshot (timezone, schedule_horizon, time_window, …)
     * @param  array<string, mixed>  $context  Schedule context from {@see TaskAssistantScheduleContextBuilder}
     * @param  array<string, mixed>  $digest  Placement digest from the spill engine
     * @param  array<int, array<string, mixed>>  $proposals
     * @param  array<string, mixed>  $scheduleOptions  Options passed to {@see TaskAssistantStructuredFlowGenerator::generateDailySchedule}
     * @return array<string, mixed>
     */
    public function enrich(
        array $snapshot,
        array $context,
        array $digest,
        array $proposals,
        array $scheduleOptions,
    ): array {
        $timezoneName = (string) ($snapshot['timezone'] ?? config('app.timezone', 'UTC'));
        try {
            $timezone = new \DateTimeZone($timezoneName);
        } catch (\Throwable) {
            $timezone = new \DateTimeZone('UTC');
        }

        $requestedScope = $this->buildRequestedScope($snapshot, $context, $scheduleOptions);
        $engineNotes = $this->buildEngineNotes($digest);
        $placementTimeCheck = $this->buildPlacementTimeCheck($snapshot, $proposals, $timezone);
        $nearestAvailableWindow = $this->resolveNearestAvailableWindow($snapshot, $requestedScope, $timezone);

        $triggers = $this->collectTriggers(
            $snapshot,
            $context,
            $digest,
            $proposals,
            $placementTimeCheck,
            $scheduleOptions
        );

        $digest['confirmation_signals'] = [
            'triggers' => array_values(array_unique($triggers)),
            'requested_scope' => $requestedScope,
            'engine_notes' => $engineNotes,
            'placement_time_check' => $placementTimeCheck,
            'nearest_available_window' => $nearestAvailableWindow,
        ];
        $digest['explainability'] = [
            'window_selection_explanation' => $this->buildWindowSelectionExplanation($requestedScope, $engineNotes),
            'fallback_choice_explanation' => $this->buildFallbackChoiceExplanation($engineNotes),
        ];
        $digest['explainability_struct'] = [
            'window_selection' => [
                'reason_code_primary' => $this->windowSelectionReasonCode($requestedScope),
                'time_window' => is_array($requestedScope['time_window'] ?? null) ? $requestedScope['time_window'] : null,
                'horizon' => is_array($requestedScope['schedule_horizon'] ?? null) ? $requestedScope['schedule_horizon'] : null,
            ],
            'fallback' => [
                'reason_code_primary' => $this->fallbackReasonCode($engineNotes),
                'fallback_mode' => (string) ($engineNotes['fallback_mode'] ?? ''),
                'fallback_trigger_reason' => (string) ($engineNotes['fallback_trigger_reason'] ?? ''),
            ],
        ];

        return $digest;
    }

    /**
     * @param  array<string, mixed>  $requestedScope
     * @param  array<string, mixed>  $engineNotes
     */
    private function buildWindowSelectionExplanation(array $requestedScope, array $engineNotes): string
    {
        $window = is_array($requestedScope['time_window'] ?? null) ? $requestedScope['time_window'] : null;
        $start = is_array($window) ? trim((string) ($window['start'] ?? '')) : '';
        $end = is_array($window) ? trim((string) ($window['end'] ?? '')) : '';
        $countShortfall = (int) ($engineNotes['count_shortfall'] ?? 0);

        if ($start !== '' && $end !== '') {
            $line = "Plan first targets {$start} to {$end} to match your requested availability.";
            if ($countShortfall > 0) {
                $line .= ' Some rows could not fit there, so follow-up options are offered.';
            }

            return $line;
        }

        return 'Plan uses the earliest conflict-free windows across your active horizon.';
    }

    /**
     * @param  array<string, mixed>  $engineNotes
     */
    private function buildFallbackChoiceExplanation(array $engineNotes): ?string
    {
        $fallbackMode = trim((string) ($engineNotes['fallback_mode'] ?? ''));
        if ($fallbackMode === '') {
            return null;
        }

        return match ($fallbackMode) {
            'auto_relaxed_today_or_tomorrow' => 'Fallback widened placement to nearby days when the original window had no valid fit.',
            default => 'Fallback mode was used to keep a feasible schedule draft.',
        };
    }

    /**
     * @param  array<string, mixed>  $requestedScope
     */
    private function windowSelectionReasonCode(array $requestedScope): string
    {
        $window = is_array($requestedScope['time_window'] ?? null) ? $requestedScope['time_window'] : null;
        $start = is_array($window) ? trim((string) ($window['start'] ?? '')) : '';
        $end = is_array($window) ? trim((string) ($window['end'] ?? '')) : '';

        return ($start !== '' && $end !== '') ? 'window_matched_request' : 'window_auto_selected';
    }

    /**
     * @param  array<string, mixed>  $engineNotes
     */
    private function fallbackReasonCode(array $engineNotes): string
    {
        $fallbackMode = trim((string) ($engineNotes['fallback_mode'] ?? ''));
        if ($fallbackMode === '') {
            return 'no_fallback_needed';
        }

        return match ($fallbackMode) {
            'auto_relaxed_today_or_tomorrow' => 'fallback_auto_relaxed_window',
            default => 'fallback_other_mode',
        };
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $scheduleOptions
     * @return array<string, mixed>
     */
    private function buildRequestedScope(array $snapshot, array $context, array $scheduleOptions): array
    {
        $horizon = is_array($snapshot['schedule_horizon'] ?? null) ? $snapshot['schedule_horizon'] : [];
        $tw = is_array($snapshot['time_window'] ?? null) ? $snapshot['time_window'] : null;

        return [
            'schedule_horizon' => [
                'mode' => (string) ($horizon['mode'] ?? ''),
                'start_date' => (string) ($horizon['start_date'] ?? ''),
                'end_date' => (string) ($horizon['end_date'] ?? ''),
                'label' => (string) ($horizon['label'] ?? ''),
            ],
            'time_window' => is_array($tw) ? [
                'start' => (string) ($tw['start'] ?? ''),
                'end' => (string) ($tw['end'] ?? ''),
            ] : null,
            'time_window_strict' => (bool) ($context['time_window_strict'] ?? false),
            'time_window_hint' => is_string($scheduleOptions['time_window_hint'] ?? null)
                ? (string) $scheduleOptions['time_window_hint']
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $digest
     * @return array<string, mixed>
     */
    private function buildEngineNotes(array $digest): array
    {
        $unplaced = is_array($digest['unplaced_units'] ?? null) ? $digest['unplaced_units'] : [];

        return [
            'fallback_mode' => (string) ($digest['fallback_mode'] ?? ''),
            'fallback_trigger_reason' => (string) ($digest['fallback_trigger_reason'] ?? ''),
            'unplaced_units_count' => count($unplaced),
            'partial_placed_count' => (int) ($digest['partial_placed_count'] ?? 0),
            'top_n_shortfall' => (bool) ($digest['top_n_shortfall'] ?? false),
            'count_shortfall' => (int) ($digest['count_shortfall'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<int, array<string, mixed>>  $proposals
     * @return array<string, mixed>
     */
    private function buildPlacementTimeCheck(array $snapshot, array $proposals, \DateTimeZone $timezone): array
    {
        $window = is_array($snapshot['time_window'] ?? null) ? $snapshot['time_window'] : null;
        $winStart = is_array($window) && is_string($window['start'] ?? null) ? trim((string) $window['start']) : '';
        $winEnd = is_array($window) && is_string($window['end'] ?? null) ? trim((string) $window['end']) : '';

        $meaningful = $this->isMeaningfulTimeWindow($winStart, $winEnd);
        $horizon = is_array($snapshot['schedule_horizon'] ?? null) ? $snapshot['schedule_horizon'] : [];
        $hStart = trim((string) ($horizon['start_date'] ?? ''));
        $hEnd = trim((string) ($horizon['end_date'] ?? ''));

        $rows = [];
        $anyOutsideWindow = false;
        $anyOutsideHorizon = false;

        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            if (trim((string) ($proposal['title'] ?? '')) === 'No schedulable items found') {
                continue;
            }
            $startRaw = trim((string) ($proposal['start_datetime'] ?? ''));
            if ($startRaw === '') {
                continue;
            }
            try {
                $start = new \DateTimeImmutable($startRaw);
            } catch (\Throwable) {
                continue;
            }
            $local = $start->setTimezone($timezone);
            $day = $local->format('Y-m-d');
            $timeLabel = $local->format('H:i');

            $insideWindow = true;
            if ($meaningful && $winStart !== '' && $winEnd !== '') {
                $insideWindow = $this->isLocalTimeWithinWindow($local, $winStart, $winEnd);
            }
            if (! $insideWindow) {
                $anyOutsideWindow = true;
            }

            $insideHorizon = true;
            if ($hStart !== '' && $day < $hStart) {
                $insideHorizon = false;
            }
            if ($hEnd !== '' && $day > $hEnd) {
                $insideHorizon = false;
            }
            if (! $insideHorizon) {
                $anyOutsideHorizon = true;
            }

            $rows[] = [
                'title' => (string) ($proposal['title'] ?? ''),
                'start_local_date' => $day,
                'start_local_time' => $timeLabel,
                'inside_requested_time_window' => $insideWindow,
                'inside_schedule_horizon_dates' => $insideHorizon,
            ];
        }

        return [
            'meaningful_time_window' => $meaningful,
            'window_start' => $winStart,
            'window_end' => $winEnd,
            'horizon_start_date' => $hStart,
            'horizon_end_date' => $hEnd,
            'rows' => $rows,
            'any_placement_outside_requested_time_window' => $anyOutsideWindow,
            'any_placement_outside_horizon_dates' => $anyOutsideHorizon,
        ];
    }

    /**
     * @param  array<string, mixed>  $digest
     * @param  array<int, array<string, mixed>>  $proposals
     * @param  array<string, mixed>  $placementTimeCheck
     * @param  array<string, mixed>  $scheduleOptions
     * @return list<string>
     */
    private function collectTriggers(
        array $snapshot,
        array $context,
        array $digest,
        array $proposals,
        array $placementTimeCheck,
        array $scheduleOptions,
    ): array {
        $triggers = [];

        $empty = $this->isEmptyPlacement($proposals);
        if ($empty) {
            $triggers[] = 'empty_placement';
        }

        $unplaced = is_array($digest['unplaced_units'] ?? null) ? $digest['unplaced_units'] : [];
        if ($unplaced !== [] && ! $empty && $this->unplacedHasExplicitTargetFailure($unplaced, $scheduleOptions)) {
            $triggers[] = 'unplaced_units';
        }

        $fallbackMode = (string) ($digest['fallback_mode'] ?? '');
        if ($fallbackMode === 'auto_relaxed_today_or_tomorrow') {
            $triggers[] = 'adaptive_relaxed_placement';
        }

        $strict = (bool) ($context['time_window_strict'] ?? false);
        if ($strict && ($empty || $unplaced !== [])) {
            $triggers[] = 'strict_window_no_fit';
        }

        if ((bool) ($digest['top_n_shortfall'] ?? false)) {
            $triggers[] = 'top_n_shortfall';
        }

        if (
            ($placementTimeCheck['meaningful_time_window'] ?? false)
            && ($placementTimeCheck['any_placement_outside_requested_time_window'] ?? false)
            && ! $empty
        ) {
            $triggers[] = 'requested_window_unsatisfied';
        }

        if (
            ($placementTimeCheck['any_placement_outside_horizon_dates'] ?? false)
            && ! $empty
        ) {
            $triggers[] = 'placement_outside_horizon';
        }

        $hint = is_string($scheduleOptions['time_window_hint'] ?? null) ? (string) $scheduleOptions['time_window_hint'] : '';
        if ($hint !== '' && ($placementTimeCheck['any_placement_outside_requested_time_window'] ?? false) && ! $empty) {
            $triggers[] = 'hinted_window_unsatisfied';
        }

        return $triggers;
    }

    /**
     * @param  array<int, mixed>  $unplaced
     * @param  array<string, mixed>  $scheduleOptions
     */
    private function unplacedHasExplicitTargetFailure(array $unplaced, array $scheduleOptions): bool
    {
        $targets = is_array($scheduleOptions['target_entities'] ?? null) ? $scheduleOptions['target_entities'] : [];
        $wanted = [];
        foreach ($targets as $target) {
            if (! is_array($target)) {
                continue;
            }
            if ((string) ($target['entity_type'] ?? '') !== 'task') {
                continue;
            }
            $id = (int) ($target['entity_id'] ?? 0);
            if ($id > 0) {
                $wanted[$id] = true;
            }
        }

        if ($wanted === []) {
            return false;
        }

        foreach ($unplaced as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((string) ($row['reason'] ?? '') !== 'horizon_exhausted') {
                continue;
            }
            $eid = (int) ($row['entity_id'] ?? 0);
            if ($eid > 0 && isset($wanted[$eid])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     */
    private function isEmptyPlacement(array $proposals): bool
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

    private function isMeaningfulTimeWindow(string $start, string $end): bool
    {
        if ($start === '' || $end === '') {
            return false;
        }

        $s = $this->timeStringToMinutes($start);
        $e = $this->timeStringToMinutes($end);

        if ($s <= 5 && $e >= 24 * 60 - 5) {
            return false;
        }

        return true;
    }

    private function timeStringToMinutes(string $t): int
    {
        $t = trim($t);
        $parts = explode(':', $t);
        $h = (int) ($parts[0] ?? 0);
        $m = (int) ($parts[1] ?? 0);
        $s = isset($parts[2]) ? (int) $parts[2] : 0;

        return max(0, $h * 60 + $m + (int) floor($s / 60));
    }

    private function isLocalTimeWithinWindow(\DateTimeImmutable $local, string $winStart, string $winEnd): bool
    {
        $cur = $this->timeStringToMinutes($local->format('H:i:s'));
        $a = $this->timeStringToMinutes($winStart);
        $b = $this->timeStringToMinutes($winEnd);

        if ($b < $a) {
            return $cur >= $a || $cur <= $b;
        }

        return $cur >= $a && $cur <= $b;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $requestedScope
     * @return array<string, string>|null
     */
    private function resolveNearestAvailableWindow(array $snapshot, array $requestedScope, \DateTimeZone $timezone): ?array
    {
        $horizon = is_array($requestedScope['schedule_horizon'] ?? null) ? $requestedScope['schedule_horizon'] : [];
        $timeWindow = is_array($requestedScope['time_window'] ?? null) ? $requestedScope['time_window'] : [];

        $windowStart = trim((string) ($timeWindow['start'] ?? '08:00'));
        $windowEnd = trim((string) ($timeWindow['end'] ?? '22:00'));
        if ($windowStart === '' || $windowEnd === '') {
            $windowStart = '08:00';
            $windowEnd = '22:00';
        }

        $startDateRaw = trim((string) ($horizon['start_date'] ?? ''));
        if ($startDateRaw === '') {
            $startDateRaw = (string) ($snapshot['today'] ?? (new \DateTimeImmutable('now', $timezone))->format('Y-m-d'));
        }

        try {
            $startDate = new \DateTimeImmutable($startDateRaw, $timezone);
        } catch (\Throwable) {
            $startDate = new \DateTimeImmutable('now', $timezone);
        }

        $dayRange = 7;
        for ($offset = 0; $offset <= $dayRange; $offset++) {
            $day = $startDate->modify("+{$offset} day");
            $busy = $this->collectBusyIntervalsForDay($snapshot, $day, $timezone);
            $slot = $this->findFirstFreeWindowForDay($day, $windowStart, $windowEnd, $busy, $timezone);
            if ($slot === null) {
                continue;
            }

            return $slot;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array{start:\DateTimeImmutable,end:\DateTimeImmutable}>
     */
    private function collectBusyIntervalsForDay(array $snapshot, \DateTimeImmutable $day, \DateTimeZone $timezone): array
    {
        $dayStart = $day->setTime(0, 0, 0);
        $dayEnd = $day->setTime(23, 59, 59);
        $intervals = [];

        $events = is_array($snapshot['events_for_busy'] ?? null) ? $snapshot['events_for_busy'] : [];
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            $startRaw = trim((string) ($event['starts_at'] ?? ''));
            $endRaw = trim((string) ($event['ends_at'] ?? ''));
            if ($startRaw === '' || $endRaw === '') {
                continue;
            }
            try {
                $start = (new \DateTimeImmutable($startRaw))->setTimezone($timezone);
                $end = (new \DateTimeImmutable($endRaw))->setTimezone($timezone);
            } catch (\Throwable) {
                continue;
            }
            if ($end <= $dayStart || $start >= $dayEnd) {
                continue;
            }
            $intervals[] = [
                'start' => $start < $dayStart ? $dayStart : $start,
                'end' => $end > $dayEnd ? $dayEnd : $end,
            ];
        }

        $classes = is_array($snapshot['school_class_busy_intervals'] ?? null) ? $snapshot['school_class_busy_intervals'] : [];
        foreach ($classes as $interval) {
            if (! is_array($interval)) {
                continue;
            }
            $startRaw = trim((string) ($interval['start'] ?? ''));
            $endRaw = trim((string) ($interval['end'] ?? ''));
            if ($startRaw === '' || $endRaw === '') {
                continue;
            }
            try {
                $start = (new \DateTimeImmutable($startRaw))->setTimezone($timezone);
                $end = (new \DateTimeImmutable($endRaw))->setTimezone($timezone);
            } catch (\Throwable) {
                continue;
            }
            if ($end <= $dayStart || $start >= $dayEnd) {
                continue;
            }
            $intervals[] = [
                'start' => $start < $dayStart ? $dayStart : $start,
                'end' => $end > $dayEnd ? $dayEnd : $end,
            ];
        }

        usort($intervals, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $intervals;
    }

    /**
     * @param  list<array{start:\DateTimeImmutable,end:\DateTimeImmutable}>  $busyIntervals
     * @return array<string, string>|null
     */
    private function findFirstFreeWindowForDay(
        \DateTimeImmutable $day,
        string $windowStart,
        string $windowEnd,
        array $busyIntervals,
        \DateTimeZone $timezone
    ): ?array {
        $startMinutes = $this->timeStringToMinutes($windowStart);
        $endMinutes = $this->timeStringToMinutes($windowEnd);
        if ($endMinutes <= $startMinutes) {
            return null;
        }

        $minimumWindowMinutes = 60;
        $slotStart = $day->setTimezone($timezone)->setTime(intdiv($startMinutes, 60), $startMinutes % 60, 0);
        $slotEndBoundary = $day->setTimezone($timezone)->setTime(intdiv($endMinutes, 60), $endMinutes % 60, 0);

        foreach ($busyIntervals as $busy) {
            $busyStart = $busy['start'];
            $busyEnd = $busy['end'];
            if ($busyEnd <= $slotStart) {
                continue;
            }
            if ($busyStart >= $slotEndBoundary) {
                break;
            }

            if ($busyStart > $slotStart) {
                $gapMinutes = (int) floor(($busyStart->getTimestamp() - $slotStart->getTimestamp()) / 60);
                if ($gapMinutes >= $minimumWindowMinutes) {
                    $candidateEnd = $slotStart->modify('+'.min($gapMinutes, 180).' minutes');

                    return $this->formatNearestWindowCandidate($day, $slotStart, $candidateEnd);
                }
            }

            if ($busyEnd > $slotStart) {
                $slotStart = $busyEnd;
            }
        }

        if ($slotStart < $slotEndBoundary) {
            $remainingMinutes = (int) floor(($slotEndBoundary->getTimestamp() - $slotStart->getTimestamp()) / 60);
            if ($remainingMinutes >= $minimumWindowMinutes) {
                $candidateEnd = $slotStart->modify('+'.min($remainingMinutes, 180).' minutes');

                return $this->formatNearestWindowCandidate($day, $slotStart, $candidateEnd);
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function formatNearestWindowCandidate(\DateTimeImmutable $day, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $dayLabel = $day->format('M j, Y');
        $startLabel = $start->format('g:i A');
        $endLabel = $end->format('g:i A');
        $daypart = match (true) {
            (int) $start->format('H') < 12 => 'morning',
            (int) $start->format('H') < 18 => 'afternoon',
            default => 'evening',
        };

        return [
            'date' => $day->format('Y-m-d'),
            'date_label' => $dayLabel,
            'chip_label' => $day->format('M j'),
            'daypart' => $daypart,
            'start_time' => $start->format('H:i'),
            'end_time' => $end->format('H:i'),
            'window_label' => "{$startLabel}-{$endLabel}",
            'display_label' => "{$dayLabel} {$startLabel}-{$endLabel}",
        ];
    }
}
