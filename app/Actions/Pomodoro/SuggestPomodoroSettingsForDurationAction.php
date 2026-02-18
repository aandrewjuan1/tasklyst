<?php

namespace App\Actions\Pomodoro;

/**
 * Suggests Pomodoro settings that fit a task's duration.
 * Does not persist; returns an array suitable for UI "use suggested" or display.
 *
 * @return array{work_duration_minutes: int, short_break_minutes: int, long_break_minutes: int, long_break_after_pomodoros: int, suggested_pomodoro_count: int}|null
 */
class SuggestPomodoroSettingsForDurationAction
{
    public function execute(int $taskDurationMinutes): ?array
    {
        if ($taskDurationMinutes < 1) {
            return null;
        }

        $minWork = config('pomodoro.min_duration_minutes', 1);
        $maxWork = config('pomodoro.max_work_duration_minutes', 120);
        $defaultWork = config('pomodoro.defaults.work_duration_minutes', 25);
        $defaultShort = config('pomodoro.defaults.short_break_minutes', 5);
        $defaultLong = config('pomodoro.defaults.long_break_minutes', 15);
        $longBreakAfterMin = 2;
        $longBreakAfterMax = 10;

        $workDurationMinutes = $defaultWork;
        $suggestedPomodoroCount = 1;

        if ($taskDurationMinutes <= $defaultWork) {
            $workDurationMinutes = (int) max($minWork, min($taskDurationMinutes, $maxWork));
        } else {
            $cycleMinutes = $defaultWork + $defaultShort;
            $suggestedPomodoroCount = (int) floor(($taskDurationMinutes + $defaultShort) / $cycleMinutes);
            $suggestedPomodoroCount = max(1, min($suggestedPomodoroCount, $longBreakAfterMax));
        }

        $longBreakAfterPomodoros = max($longBreakAfterMin, min($suggestedPomodoroCount, $longBreakAfterMax));

        return [
            'work_duration_minutes' => $workDurationMinutes,
            'short_break_minutes' => $defaultShort,
            'long_break_minutes' => $defaultLong,
            'long_break_after_pomodoros' => $longBreakAfterPomodoros,
            'suggested_pomodoro_count' => $suggestedPomodoroCount,
        ];
    }
}
