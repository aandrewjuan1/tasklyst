<?php

use App\Actions\Pomodoro\GetOrCreatePomodoroSettingsAction;
use App\Models\PomodoroSetting;
use App\Models\User;

beforeEach(function (): void {
    $this->action = app(GetOrCreatePomodoroSettingsAction::class);
});

test('returns existing pomodoro settings when user has settings', function (): void {
    $user = User::factory()->create();
    $existing = PomodoroSetting::create(array_merge(
        PomodoroSetting::defaults(),
        ['user_id' => $user->id, 'work_duration_minutes' => 42]
    ));

    $result = $this->action->execute($user);

    expect($result->id)->toBe($existing->id)
        ->and($result->work_duration_minutes)->toBe(42);
});

test('creates pomodoro settings with defaults when user has none', function (): void {
    $user = User::factory()->create();

    $result = $this->action->execute($user);

    expect($result->user_id)->toBe($user->id)
        ->and($result->work_duration_minutes)->toBe(config('pomodoro.defaults.work_duration_minutes'))
        ->and($result->short_break_minutes)->toBe(config('pomodoro.defaults.short_break_minutes'))
        ->and(PomodoroSetting::where('user_id', $user->id)->count())->toBe(1);
});
