<?php

use App\Http\Requests\UpdateSchedulerPreferencesRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Settings')]
class extends Component {
    public string $timezone = '';

    public string $energyBias = 'balanced';

    public bool $lunchBlockEnabled = true;

    public string $lunchBlockStart = '12:00';

    public string $lunchBlockEnd = '13:00';

    public string $dayBoundsStart = '08:00';

    public string $dayBoundsEnd = '22:00';

    public function mount(): void
    {
        $user = Auth::user();
        $preferences = is_array($user->schedule_preferences) ? $user->schedule_preferences : [];
        $lunchBlock = is_array($preferences['lunch_block'] ?? null) ? $preferences['lunch_block'] : [];
        $dayBounds = is_array($preferences['day_bounds'] ?? null) ? $preferences['day_bounds'] : [];

        $this->timezone = trim((string) ($user->timezone ?? ''));
        $this->energyBias = in_array((string) ($preferences['energy_bias'] ?? 'balanced'), ['morning', 'evening', 'balanced'], true)
            ? (string) ($preferences['energy_bias'] ?? 'balanced')
            : 'balanced';
        $this->lunchBlockEnabled = (bool) ($lunchBlock['enabled'] ?? true);
        $this->lunchBlockStart = is_string($lunchBlock['start'] ?? null) ? (string) $lunchBlock['start'] : '12:00';
        $this->lunchBlockEnd = is_string($lunchBlock['end'] ?? null) ? (string) $lunchBlock['end'] : '13:00';
        $this->dayBoundsStart = is_string($dayBounds['start'] ?? null) ? (string) $dayBounds['start'] : '08:00';
        $this->dayBoundsEnd = is_string($dayBounds['end'] ?? null) ? (string) $dayBounds['end'] : '22:00';
    }

    public function updatePreferences(): void
    {
        $user = Auth::user();

        $payload = [
            'timezone' => $this->timezone !== '' ? $this->timezone : null,
            'day_bounds_start' => $this->dayBoundsStart !== '' ? $this->dayBoundsStart : null,
            'day_bounds_end' => $this->dayBoundsEnd !== '' ? $this->dayBoundsEnd : null,
            'energy_bias' => $this->energyBias !== '' ? $this->energyBias : 'balanced',
            'lunch_block_enabled' => $this->lunchBlockEnabled,
            'lunch_block_start' => $this->lunchBlockStart !== '' ? $this->lunchBlockStart : null,
            'lunch_block_end' => $this->lunchBlockEnd !== '' ? $this->lunchBlockEnd : null,
        ];
        $request = app(UpdateSchedulerPreferencesRequest::class);
        $validatedScheduler = Validator::make(
            $payload,
            $request->rules(),
            $request->messages()
        )->validate();

        $schedulePreferences = [
            'schema_version' => 1,
            'energy_bias' => $validatedScheduler['energy_bias'],
            'day_bounds' => [
                'start' => $validatedScheduler['day_bounds_start'] ?? '08:00',
                'end' => $validatedScheduler['day_bounds_end'] ?? '22:00',
            ],
            'lunch_block' => [
                'enabled' => (bool) ($validatedScheduler['lunch_block_enabled'] ?? true),
                'start' => $validatedScheduler['lunch_block_start'] ?? '12:00',
                'end' => $validatedScheduler['lunch_block_end'] ?? '13:00',
            ],
        ];

        $user->fill([
            'timezone' => $validatedScheduler['timezone'] ?? null,
            'schedule_preferences' => $schedulePreferences,
        ]);

        $user->save();

        $this->dispatch('preferences-updated');
    }
};