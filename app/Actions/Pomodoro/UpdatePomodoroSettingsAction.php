<?php

namespace App\Actions\Pomodoro;

use App\Models\PomodoroSetting;
use App\Models\User;

class UpdatePomodoroSettingsAction
{
    public function __construct(
        private GetOrCreatePomodoroSettingsAction $getOrCreatePomodoroSettingsAction
    ) {}

    /**
     * Update Pomodoro settings for the user. Creates settings if missing.
     *
     * @param  array<string, mixed>  $validated  Validated data (e.g. from PomodoroSettingsValidation::rules()).
     */
    public function execute(User $user, array $validated): PomodoroSetting
    {
        $setting = $this->getOrCreatePomodoroSettingsAction->execute($user);

        $setting->update($validated);

        return $setting->fresh();
    }
}
