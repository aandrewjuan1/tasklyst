<?php

namespace App\Support\Validation;

final class PomodoroSettingsValidation
{
    /**
     * Validation rules for updating Pomodoro settings.
     * Keys match PomodoroSetting model attributes.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        $maxWork = config('pomodoro.max_work_duration_minutes', 120);
        $minDuration = config('pomodoro.min_duration_minutes', 1);

        return [
            'work_duration_minutes' => ['required', 'integer', 'min:'.$minDuration, 'max:'.$maxWork],
            'short_break_minutes' => ['required', 'integer', 'min:1', 'max:60'],
            'long_break_minutes' => ['required', 'integer', 'min:1', 'max:60'],
            'long_break_after_pomodoros' => ['required', 'integer', 'min:2', 'max:10'],
            'auto_start_break' => ['required', 'boolean'],
            'auto_start_pomodoro' => ['required', 'boolean'],
            'sound_enabled' => ['required', 'boolean'],
            'sound_volume' => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }
}
