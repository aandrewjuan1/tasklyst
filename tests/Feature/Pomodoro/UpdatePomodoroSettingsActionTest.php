<?php

use App\Actions\Pomodoro\GetOrCreatePomodoroSettingsAction;
use App\Actions\Pomodoro\UpdatePomodoroSettingsAction;
use App\Models\PomodoroSetting;
use App\Models\User;

beforeEach(function (): void {
    $this->getOrCreateAction = app(GetOrCreatePomodoroSettingsAction::class);
    $this->updateAction = app(UpdatePomodoroSettingsAction::class);
});

test('updates existing pomodoro settings', function (): void {
    $user = User::factory()->create();
    $this->getOrCreateAction->execute($user);

    $validated = [
        'work_duration_minutes' => 50,
        'short_break_minutes' => 10,
        'long_break_minutes' => 20,
        'long_break_after_pomodoros' => 5,
        'auto_start_break' => true,
        'auto_start_pomodoro' => true,
        'sound_enabled' => false,
        'sound_volume' => 50,
    ];

    $result = $this->updateAction->execute($user, $validated);

    expect($result->work_duration_minutes)->toBe(50)
        ->and($result->short_break_minutes)->toBe(10)
        ->and($result->auto_start_break)->toBeTrue()
        ->and($result->sound_enabled)->toBeFalse()
        ->and($result->sound_volume)->toBe(50);
});

test('creates then updates when user has no settings', function (): void {
    $user = User::factory()->create();
    expect(PomodoroSetting::where('user_id', $user->id)->count())->toBe(0);

    $validated = [
        'work_duration_minutes' => 30,
        'short_break_minutes' => 5,
        'long_break_minutes' => 15,
        'long_break_after_pomodoros' => 4,
        'auto_start_break' => false,
        'auto_start_pomodoro' => false,
        'sound_enabled' => true,
        'sound_volume' => 80,
    ];

    $result = $this->updateAction->execute($user, $validated);

    expect($result->user_id)->toBe($user->id)
        ->and($result->work_duration_minutes)->toBe(30)
        ->and(PomodoroSetting::where('user_id', $user->id)->count())->toBe(1);
});
