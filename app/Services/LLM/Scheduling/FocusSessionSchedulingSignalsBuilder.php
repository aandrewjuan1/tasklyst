<?php

namespace App\Services\LLM\Scheduling;

use App\Enums\FocusSessionType;
use App\Models\FocusSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class FocusSessionSchedulingSignalsBuilder
{
    public function buildForUser(User $user, string $timezone, CarbonImmutable $now): array
    {
        $lookbackDays = (int) config('task-assistant.schedule.focus_signals.lookback_days', 30);
        $windowStart = $now->copy()->subDays(max(1, $lookbackDays));

        /** @var \Illuminate\Database\Eloquent\Collection<int, FocusSession> $workSessions */
        $workSessions = FocusSession::query()
            ->forUser($user->id)
            ->work()
            ->completed()
            ->whereNotNull('started_at', 'and')
            ->where('started_at', '>=', $windowStart)
            ->orderBy('started_at', 'asc')
            ->get();

        $workStartsMinutes = [];
        $workEffectiveMinutes = [];
        $energyMorningCount = 0;
        $energyAfternoonCount = 0;
        $energyEveningCount = 0;

        foreach ($workSessions as $session) {
            if (! ($session->started_at instanceof CarbonInterface)) {
                continue;
            }

            $localStart = CarbonImmutable::instance($session->started_at)->setTimezone($timezone);
            $minuteOfDay = ((int) $localStart->format('H')) * 60 + (int) $localStart->format('i');
            $workStartsMinutes[] = $minuteOfDay;

            $hour = (int) $localStart->format('H');
            $bucket = ScheduleEnergyDaypart::bucketForStartHour($hour);
            if ($bucket === 'morning') {
                $energyMorningCount++;
            } elseif ($bucket === 'afternoon') {
                $energyAfternoonCount++;
            } elseif ($bucket === 'evening') {
                $energyEveningCount++;
            }

            $effectiveSeconds = $session->effectiveWorkSeconds($now);
            if ($effectiveSeconds === null) {
                continue;
            }

            $minutes = (int) round($effectiveSeconds / 60);
            $minutes = max(10, min(240, $minutes));
            $workEffectiveMinutes[] = $minutes;
        }

        $workCount = count($workSessions);
        $energyMinWorkSessions = (int) config('task-assistant.schedule.focus_signals.min_work_sessions_energy', 8);

        $energyBias = 'balanced';
        $energyConfidence = 0.0;
        if ($workCount >= $energyMinWorkSessions) {
            $morningShare = $energyMorningCount / $workCount;
            $afternoonShare = $energyAfternoonCount / $workCount;
            $eveningShare = $energyEveningCount / $workCount;

            $shares = [
                'morning' => $morningShare,
                'afternoon' => $afternoonShare,
                'evening' => $eveningShare,
            ];
            arsort($shares);
            $ordered = array_values($shares);
            $firstShare = $ordered[0] ?? 0.0;
            $secondShare = $ordered[1] ?? 0.0;
            $winnerKey = array_key_first($shares);

            $dominanceOk = $firstShare >= 0.40
                && (
                    $secondShare === 0.0
                    || ($secondShare > 0.0 && $firstShare >= $secondShare * 1.25)
                );

            if ($dominanceOk && is_string($winnerKey) && $firstShare > 0.0) {
                $energyBias = $winnerKey;
                $energyConfidence = min(1.0, $firstShare);
            } else {
                $energyBias = 'balanced';
                $energyConfidence = min(1.0, max(0.45, 1.0 - ($firstShare - $secondShare)));
            }
        }

        $durationMinWorkSessions = (int) config('task-assistant.schedule.focus_signals.min_work_sessions_duration', 6);
        $workDurationMinutesPredicted = null;
        $workDurationConfidence = 0.0;
        if ($workEffectiveMinutes !== []) {
            $distinctCount = count($workEffectiveMinutes);
            if ($distinctCount >= $durationMinWorkSessions) {
                $workDurationMinutesPredicted = (int) round($this->median($workEffectiveMinutes));
                $workDurationConfidence = min(1.0, $distinctCount / 20.0);
            }
        }

        // Infer time range where the user tends to start work.
        $dayBoundsStartMinutes = null;
        $dayBoundsEndMinutes = null;
        $dayBoundsConfidence = 0.0;
        $minWorkForDayBounds = (int) config('task-assistant.schedule.focus_signals.min_work_sessions_day_bounds', 10);
        if ($workStartsMinutes !== [] && $workCount >= $minWorkForDayBounds) {
            sort($workStartsMinutes);
            $qStart = (int) $this->nearestPercentileValue($workStartsMinutes, 0.10);
            $qEnd = (int) $this->nearestPercentileValue($workStartsMinutes, 0.90);

            $predWorkMinutes = $workDurationMinutesPredicted ?? (int) config('task-assistant.schedule.focus_signals.fallback_work_duration_minutes', 60);

            $startMinutes = $qStart;
            $endMinutes = $qEnd + $predWorkMinutes;

            $minSpanMinutes = (int) config('task-assistant.schedule.focus_signals.min_day_bounds_span_minutes', 8 * 60);
            if (($endMinutes - $startMinutes) < $minSpanMinutes) {
                $endMinutes = $startMinutes + $minSpanMinutes;
            }

            // Keep the day bounds within sane product constraints.
            $startMinutes = max(6 * 60, min(12 * 60, $startMinutes));
            $endMinutes = max(14 * 60, min(23 * 60, $endMinutes));

            if ($endMinutes > $startMinutes) {
                $dayBoundsStartMinutes = $startMinutes;
                $dayBoundsEndMinutes = $endMinutes;

                $dayBoundsConfidence = min(1.0, $workCount / 20.0);
            }
        }

        // Lunch block inferred from completed break sessions in a midday band.
        $breakTypes = [
            FocusSessionType::ShortBreak->value,
            FocusSessionType::LongBreak->value,
        ];
        /** @var \Illuminate\Database\Eloquent\Collection<int, FocusSession> $breakSessions */
        $breakSessions = FocusSession::query()
            ->forUser($user->id)
            ->whereIn('type', $breakTypes, 'and', false)
            ->completed()
            ->whereNotNull('started_at', 'and')
            ->where('started_at', '>=', $windowStart)
            ->orderBy('started_at', 'asc')
            ->get();

        $lunchStartMinutesList = [];
        $lunchDurationMinutesList = [];

        foreach ($breakSessions as $session) {
            if (! ($session->started_at instanceof CarbonInterface) || ! ($session->ended_at instanceof CarbonInterface)) {
                continue;
            }

            $localStart = CarbonImmutable::instance($session->started_at)->setTimezone($timezone);
            $hour = (int) $localStart->format('H');
            if ($hour < 10 || $hour > 15) {
                continue;
            }

            $minuteOfDay = ((int) $localStart->format('H')) * 60 + (int) $localStart->format('i');
            $pausedSeconds = max(0, (int) ($session->paused_seconds ?? 0));
            $effectiveSeconds = max(
                0,
                ((int) $session->ended_at->getTimestamp() - (int) $session->started_at->getTimestamp()) - $pausedSeconds
            );
            $durationMinutes = (int) round($effectiveSeconds / 60);
            if ($durationMinutes <= 0) {
                continue;
            }

            $lunchStartMinutesList[] = $minuteOfDay;
            $lunchDurationMinutesList[] = max(10, min(120, $durationMinutes));
        }

        $lunchBlockConfidence = 0.0;
        $lunchStartMinutesPredicted = null;
        $lunchDurationMinutesPredicted = null;

        $minBreakSessionsForLunch = (int) config('task-assistant.schedule.focus_signals.min_break_sessions_lunch', 5);
        if ($lunchStartMinutesList !== [] && count($lunchStartMinutesList) >= $minBreakSessionsForLunch) {
            $lunchStartMinutesRaw = $this->median($lunchStartMinutesList);
            $lunchDurationMinutesRaw = $this->median($lunchDurationMinutesList);

            $lunchStartMinutesPredicted = (int) round($this->roundToStep($lunchStartMinutesRaw, 5));
            $lunchDurationMinutesPredicted = (int) round($lunchDurationMinutesRaw);

            $lunchStartMinutesPredicted = max(10 * 60 + 30, min(13 * 60, $lunchStartMinutesPredicted));
            $lunchEndMinutes = $lunchStartMinutesPredicted + $lunchDurationMinutesPredicted;
            $lunchEndMinutes = max(12 * 60 + 30, min(14 * 60 + 30, $lunchEndMinutes));

            if ($lunchEndMinutes > $lunchStartMinutesPredicted + 10) {
                $lunchDurationMinutesPredicted = $lunchEndMinutes - $lunchStartMinutesPredicted;
                $lunchBlockConfidence = min(1.0, count($lunchStartMinutesList) / 10.0);
            } else {
                $lunchStartMinutesPredicted = null;
                $lunchDurationMinutesPredicted = null;
                $lunchBlockConfidence = 0.0;
            }
        }

        $gapMinutesPredicted = null;
        $gapConfidence = 0.0;
        $minWorkGapsForPrediction = (int) config('task-assistant.schedule.focus_signals.min_work_gaps', 5);
        $gapSamplesUsed = 0;

        if ($workSessions->count() >= 2) {
            $prev = null;
            $gaps = [];
            foreach ($workSessions as $session) {
                if ($prev === null) {
                    $prev = $session;

                    continue;
                }

                if (! ($prev->ended_at instanceof CarbonInterface) || ! ($session->started_at instanceof CarbonInterface)) {
                    $prev = $session;

                    continue;
                }

                $prevEnd = CarbonImmutable::instance($prev->ended_at)->setTimezone($timezone);
                $nextStart = CarbonImmutable::instance($session->started_at)->setTimezone($timezone);

                $gapMinutes = (int) floor(max(0, ($nextStart->getTimestamp() - $prevEnd->getTimestamp()) / 60));
                if ($gapMinutes === 0) {
                    // Keep, but allow trimming later; 0 can be meaningful for very rapid successive focus.
                    $gaps[] = 0;
                } else {
                    $gaps[] = max(0, min(120, $gapMinutes));
                }

                $prev = $session;
            }

            $gapCount = count($gaps);
            $gapSamplesUsed = $gapCount;
            if ($gapCount >= $minWorkGapsForPrediction) {
                sort($gaps);
                $q10 = (int) $this->nearestPercentileValue($gaps, 0.10);
                $q90 = (int) $this->nearestPercentileValue($gaps, 0.90);
                $trimmed = array_values(array_filter($gaps, static function (int $g) use ($q10, $q90): bool {
                    return $g >= $q10 && $g <= $q90;
                }));

                $use = $trimmed !== [] ? $trimmed : $gaps;
                $gapMinutesPredicted = (int) round($this->median($use));
                $gapMinutesPredicted = max(0, min(60, $gapMinutesPredicted));
                $gapConfidence = min(1.0, $gapCount / 20.0);
            }
        }

        /** @var FocusSession|null $activeSession */
        $activeSession = FocusSession::query()
            ->forUser($user->id)
            ->work()
            ->where('completed', false)
            ->whereNull('ended_at', 'and', false)
            ->whereNull('paused_at', 'and', false)
            ->whereNotNull('started_at', 'and')
            ->orderByDesc('started_at')
            ->first();

        $activeFocusSession = null;
        if ($activeSession instanceof FocusSession && $activeSession->started_at instanceof CarbonInterface) {
            $startedAtLocal = CarbonImmutable::instance($activeSession->started_at)->setTimezone($timezone);
            $durationSeconds = max(0, (int) ($activeSession->duration_seconds ?? 0));
            if ($durationSeconds > 0) {
                $projectedEnd = $startedAtLocal->addSeconds($durationSeconds);
            } else {
                $fallbackMinutes = max(
                    1,
                    (int) config('task-assistant.schedule.focus_signals.active_session_fallback_minutes', 25)
                );
                $projectedEnd = $now->max($startedAtLocal)->addMinutes($fallbackMinutes);
            }

            $activeFocusSession = [
                'projected_end_at_iso' => $projectedEnd->toIso8601String(),
                'started_at_iso' => $startedAtLocal->toIso8601String(),
                'duration_seconds' => $durationSeconds,
            ];
        }

        $overrideEnergyBias = $energyBias;
        $overrideDayBounds = null;
        if ($dayBoundsStartMinutes !== null && $dayBoundsEndMinutes !== null) {
            $overrideDayBounds = [
                'start' => $this->minutesToClockString($dayBoundsStartMinutes),
                'end' => $this->minutesToClockString($dayBoundsEndMinutes),
            ];
        }

        $overrideLunchBlock = null;
        if ($lunchStartMinutesPredicted !== null && $lunchDurationMinutesPredicted !== null) {
            $lunchEndMinutes = $lunchStartMinutesPredicted + $lunchDurationMinutesPredicted;
            $overrideLunchBlock = [
                'enabled' => true,
                'start' => $this->minutesToClockString($lunchStartMinutesPredicted),
                'end' => $this->minutesToClockString($lunchEndMinutes),
            ];
        }

        return [
            'schedule_preferences_override' => [
                'energy_bias' => $overrideEnergyBias,
                'day_bounds' => $overrideDayBounds,
                'lunch_block' => $overrideLunchBlock,
            ],
            'energy_bias_confidence' => $energyConfidence,
            'day_bounds_confidence' => $dayBoundsConfidence,
            'lunch_block_confidence' => $lunchBlockConfidence,
            'work_duration_minutes_predicted' => $workDurationMinutesPredicted,
            'work_duration_confidence' => $workDurationConfidence,
            'gap_minutes_predicted' => $gapMinutesPredicted,
            'gap_confidence' => $gapConfidence,
            'active_focus_session' => $activeFocusSession,
            'learning_meta' => [
                'lookback_days' => $lookbackDays,
                'work_sessions_count' => $workCount,
                'morning_bucket_count' => $energyMorningCount,
                'afternoon_bucket_count' => $energyAfternoonCount,
                'evening_bucket_count' => $energyEveningCount,
                'work_effective_minutes_samples' => count($workEffectiveMinutes),
                'lunch_break_sessions_count' => count($lunchStartMinutesList),
                'gap_samples_used' => $gapSamplesUsed,
            ],
        ];
    }

    /**
     * @param  list<int|float>  $values
     */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $count = count($values);
        $mid = (int) floor($count / 2);

        if ($count % 2 === 1) {
            return (float) $values[$mid];
        }

        return ((float) $values[$mid - 1] + (float) $values[$mid]) / 2.0;
    }

    /**
     * Returns a value from the list at the nearest observed percentile.
     *
     * @param  list<int>  $sortedValues
     */
    private function nearestPercentileValue(array $sortedValues, float $percentile): int
    {
        $count = count($sortedValues);
        if ($count === 0) {
            return 0;
        }

        $p = max(0.0, min(1.0, $percentile));
        $idx = (int) round($p * ($count - 1));

        return (int) $sortedValues[$idx];
    }

    private function roundToStep(float $value, int $step): float
    {
        if ($step <= 1) {
            return $value;
        }

        return round($value / $step) * $step;
    }

    private function minutesToClockString(int $minutes): string
    {
        $m = max(0, $minutes);
        $hour = (int) floor($m / 60);
        $minute = (int) ($m % 60);

        return sprintf('%02d:%02d', $hour, $minute);
    }
}
