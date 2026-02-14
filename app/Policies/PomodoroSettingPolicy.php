<?php

namespace App\Policies;

use App\Models\PomodoroSetting;
use App\Models\User;

class PomodoroSettingPolicy
{
    /**
     * Determine whether the user can view any pomodoro settings.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the pomodoro setting.
     */
    public function view(User $user, PomodoroSetting $pomodoroSetting): bool
    {
        return (int) $pomodoroSetting->user_id === (int) $user->id;
    }

    /**
     * Determine whether the user can update the pomodoro setting.
     */
    public function update(User $user, PomodoroSetting $pomodoroSetting): bool
    {
        return (int) $pomodoroSetting->user_id === (int) $user->id;
    }
}
