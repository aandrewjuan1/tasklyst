<?php

namespace App\Services\LLM\Scheduling;

final class ScheduleFallbackReasonExplainer
{
    /**
     * @param  array<string, mixed>  $scheduleData
     * @return list<string>
     */
    public function summarize(array $scheduleData, ?string $timeWindowHint = null): array
    {
        $digest = is_array($scheduleData['placement_digest'] ?? null) ? $scheduleData['placement_digest'] : [];
        $triggers = is_array(($digest['confirmation_signals'] ?? [])['triggers'] ?? null)
            ? ($digest['confirmation_signals'] ?? [])['triggers']
            : [];
        $requestedWindow = is_array($scheduleData['requested_window'] ?? null) ? $scheduleData['requested_window'] : [];
        $unplacedUnits = is_array($digest['unplaced_units'] ?? null) ? $digest['unplaced_units'] : [];

        $reasonFamilies = [];

        $start = trim((string) ($requestedWindow['start'] ?? ''));
        $end = trim((string) ($requestedWindow['end'] ?? ''));
        $laterShortage = false;
        if ($timeWindowHint === 'later' || in_array('strict_window_no_fit', $triggers, true)) {
            $laterShortage = true;
            if ($start !== '' && $end !== '' && $start >= $end) {
                $reasonFamilies['time_shortage'] = 'It is already late in the day, so there is almost no time left in that window.';
            } else {
                $reasonFamilies['time_shortage'] = 'There is not enough time left later today for this request.';
            }
        }

        $hasCalendarConflicts = in_array('empty_placement', $triggers, true) || in_array('unplaced_units', $triggers, true);
        if ($hasCalendarConflicts) {
            $reasonFamilies['calendar_conflict'] = 'Your current classes and events leave no open block that fits this request right now.';
        }

        $hasDurationMismatch = $this->hasDurationMismatch($unplacedUnits);
        if ($hasDurationMismatch) {
            $reasonFamilies['duration_mismatch'] = 'At least one task needs a longer focus block than what is available in this window.';
        }

        if ($laterShortage && $hasDurationMismatch) {
            $reasonFamilies['time_shortage'] = 'It is already late, and the remaining open blocks are too short for this task duration.';
            unset($reasonFamilies['duration_mismatch']);
        }

        if (in_array('placement_outside_horizon', $triggers, true)) {
            $reasonFamilies['horizon_mismatch'] = 'The available slots are outside the day range you asked for.';
        }

        if (in_array('top_n_shortfall', $triggers, true) || (bool) ($digest['top_n_shortfall'] ?? false)) {
            $requestedCount = max(1, (int) ($digest['requested_count'] ?? 1));
            $placedCount = is_array($scheduleData['proposals'] ?? null) ? count($scheduleData['proposals']) : 0;
            if ($placedCount < $requestedCount) {
                $reasonFamilies['top_n_shortfall'] = "Only {$placedCount} of {$requestedCount} requested items could fit in this draft.";
            }
        }

        $reasons = array_values(array_filter($reasonFamilies, static fn (string $line): bool => trim($line) !== ''));

        return array_slice($reasons, 0, 2);
    }

    /**
     * @param  list<array<string, mixed>>  $unplacedUnits
     */
    private function hasDurationMismatch(array $unplacedUnits): bool
    {
        foreach ($unplacedUnits as $unit) {
            if (! is_array($unit)) {
                continue;
            }

            $minutes = (int) ($unit['minutes'] ?? 0);
            $reason = (string) ($unit['reason'] ?? '');
            if ($minutes >= 90 && in_array($reason, ['horizon_exhausted', 'strict_window_no_fit', 'window_conflict'], true)) {
                return true;
            }
        }

        return false;
    }
}
