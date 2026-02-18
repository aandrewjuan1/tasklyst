<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PomodoroSetting extends Model
{
    protected $fillable = [
        'user_id',
        'work_duration_minutes',
        'short_break_minutes',
        'long_break_minutes',
        'long_break_after_pomodoros',
        'auto_start_break',
        'auto_start_pomodoro',
        'sound_enabled',
        'sound_volume',
    ];

    protected function casts(): array
    {
        return [
            'auto_start_break' => 'boolean',
            'auto_start_pomodoro' => 'boolean',
            'sound_enabled' => 'boolean',
        ];
    }

    /**
     * Default attributes for a new PomodoroSetting (from config).
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'work_duration_minutes' => config('pomodoro.defaults.work_duration_minutes', 25),
            'short_break_minutes' => config('pomodoro.defaults.short_break_minutes', 5),
            'long_break_minutes' => config('pomodoro.defaults.long_break_minutes', 15),
            'long_break_after_pomodoros' => config('pomodoro.defaults.long_break_after_pomodoros', 4),
            'auto_start_break' => config('pomodoro.defaults.auto_start_break', false),
            'auto_start_pomodoro' => config('pomodoro.defaults.auto_start_pomodoro', false),
            'sound_enabled' => config('pomodoro.defaults.sound_enabled', true),
            'sound_volume' => config('pomodoro.defaults.sound_volume', 80),
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getWorkDurationSecondsAttribute(): int
    {
        return $this->work_duration_minutes * 60;
    }
}
