<?php

namespace App\Livewire\Concerns;

use App\Models\PomodoroSetting;
use App\Support\Validation\PomodoroSettingsValidation;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Async;
use Livewire\Attributes\Renderless;

/**
 * Trait for Livewire components that display and update Pomodoro settings.
 *
 * Requires the component to define:
 * - GetOrCreatePomodoroSettingsAction $getOrCreatePomodoroSettingsAction
 * - UpdatePomodoroSettingsAction $updatePomodoroSettingsAction
 */
trait HandlesPomodoroSettings
{
    /**
     * Load or create Pomodoro settings for the authenticated user and return as array for form binding.
     *
     * @return array<string, mixed>
     */
    #[Async]
    #[Renderless]
    public function getPomodoroSettings(): array
    {
        $user = $this->requireAuth(__('You must be logged in to view Pomodoro settings.'));
        if ($user === null) {
            return PomodoroSetting::defaults();
        }

        $setting = $this->getOrCreatePomodoroSettingsAction->execute($user);
        $this->authorize('view', $setting);

        return $setting->only([
            'work_duration_minutes',
            'short_break_minutes',
            'long_break_minutes',
            'long_break_after_pomodoros',
            'auto_start_break',
            'auto_start_pomodoro',
            'sound_enabled',
            'sound_volume',
        ]);
    }

    /**
     * Validate and update Pomodoro settings. Dispatches toast on success or validation error.
     *
     * @param  array<string, mixed>  $data
     */
    #[Async]
    #[Renderless]
    public function updatePomodoroSettings(array $data): bool
    {
        $user = $this->requireAuth(__('You must be logged in to update Pomodoro settings.'));
        if ($user === null) {
            return false;
        }

        $validator = Validator::make($data, PomodoroSettingsValidation::rules());
        if ($validator->fails()) {
            $this->dispatch('toast', type: 'error', message: __('Please fix the settings and try again.'));

            return false;
        }

        $setting = $this->getOrCreatePomodoroSettingsAction->execute($user);
        $this->authorize('update', $setting);

        $this->updatePomodoroSettingsAction->execute($user, $validator->validated());
        $this->dispatch('toast', type: 'success', message: __('Pomodoro settings saved.'));

        return true;
    }
}
