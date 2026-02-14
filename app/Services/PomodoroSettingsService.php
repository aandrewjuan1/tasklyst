<?php

namespace App\Services;

use App\Actions\Pomodoro\GetOrCreatePomodoroSettingsAction;
use App\Actions\Pomodoro\UpdatePomodoroSettingsAction;
use App\Models\PomodoroSetting;
use App\Models\User;

class PomodoroSettingsService
{
    public function __construct(
        private GetOrCreatePomodoroSettingsAction $getOrCreatePomodoroSettingsAction,
        private UpdatePomodoroSettingsAction $updatePomodoroSettingsAction
    ) {}

    public function getOrCreateForUser(User $user): PomodoroSetting
    {
        return $this->getOrCreatePomodoroSettingsAction->execute($user);
    }

    /**
     * @param  array<string, mixed>  $data  Validated settings data.
     */
    public function updateForUser(User $user, array $data): PomodoroSetting
    {
        return $this->updatePomodoroSettingsAction->execute($user, $data);
    }
}
