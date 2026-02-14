<?php

namespace App\Actions\Pomodoro;

use App\Models\PomodoroSetting;
use App\Models\User;

class GetOrCreatePomodoroSettingsAction
{
    public function execute(User $user): PomodoroSetting
    {
        $setting = PomodoroSetting::query()->where('user_id', $user->id)->first();

        if ($setting !== null) {
            return $setting;
        }

        return PomodoroSetting::create(array_merge(
            PomodoroSetting::defaults(),
            ['user_id' => $user->id]
        ));
    }
}
