<?php

use App\Actions\Pomodoro\GetNextPomodoroSessionTypeAction;
use App\Enums\FocusSessionType;
use App\Models\FocusSession;
use App\Models\PomodoroSetting;
use App\Models\User;

beforeEach(function (): void {
    $this->action = app(GetNextPomodoroSessionTypeAction::class);
});

test('returns short break after work session when not at long break interval', function (): void {
    $user = User::factory()->create();
    $settings = PomodoroSetting::create(array_merge(
        PomodoroSetting::defaults(),
        [
            'user_id' => $user->id,
            'long_break_after_pomodoros' => 4,
        ]
    ));

    $workSession = FocusSession::factory()
        ->for($user)
        ->work()
        ->create(['sequence_number' => 1, 'type' => FocusSessionType::Work]);

    $result = $this->action->execute($workSession, $settings);

    expect($result['type'])->toBe(FocusSessionType::ShortBreak)
        ->and($result['sequence_number'])->toBe(1)
        ->and($result['duration_seconds'])->toBe($settings->short_break_minutes * 60);
});

test('returns long break after work session when at long break interval', function (): void {
    $user = User::factory()->create();
    $settings = PomodoroSetting::create(array_merge(
        PomodoroSetting::defaults(),
        [
            'user_id' => $user->id,
            'long_break_after_pomodoros' => 4,
        ]
    ));

    $workSession = FocusSession::factory()
        ->for($user)
        ->work()
        ->create(['sequence_number' => 4, 'type' => FocusSessionType::Work]);

    $result = $this->action->execute($workSession, $settings);

    expect($result['type'])->toBe(FocusSessionType::LongBreak)
        ->and($result['sequence_number'])->toBe(4)
        ->and($result['duration_seconds'])->toBe($settings->long_break_minutes * 60);
});

test('returns work session after short break', function (): void {
    $user = User::factory()->create();
    $settings = PomodoroSetting::create(array_merge(
        PomodoroSetting::defaults(),
        ['user_id' => $user->id]
    ));

    // Create 2 completed work sessions so next will be 3
    FocusSession::factory()
        ->for($user)
        ->work()
        ->completed()
        ->create(['sequence_number' => 1, 'started_at' => now()]);

    FocusSession::factory()
        ->for($user)
        ->work()
        ->completed()
        ->create(['sequence_number' => 2, 'started_at' => now()]);

    $breakSession = FocusSession::factory()
        ->for($user)
        ->create(['type' => FocusSessionType::ShortBreak, 'sequence_number' => 2]);

    $result = $this->action->execute($breakSession, $settings);

    expect($result['type'])->toBe(FocusSessionType::Work)
        ->and($result['sequence_number'])->toBe(3)
        ->and($result['duration_seconds'])->toBe($settings->work_duration_minutes * 60);
});

test('returns work session after long break', function (): void {
    $user = User::factory()->create();
    $settings = PomodoroSetting::create(array_merge(
        PomodoroSetting::defaults(),
        ['user_id' => $user->id]
    ));

    // Create 4 completed work sessions so next will be 5
    FocusSession::factory()
        ->for($user)
        ->work()
        ->completed()
        ->create(['sequence_number' => 1, 'started_at' => now()]);

    FocusSession::factory()
        ->for($user)
        ->work()
        ->completed()
        ->create(['sequence_number' => 2, 'started_at' => now()]);

    FocusSession::factory()
        ->for($user)
        ->work()
        ->completed()
        ->create(['sequence_number' => 3, 'started_at' => now()]);

    FocusSession::factory()
        ->for($user)
        ->work()
        ->completed()
        ->create(['sequence_number' => 4, 'started_at' => now()]);

    $breakSession = FocusSession::factory()
        ->for($user)
        ->create(['type' => FocusSessionType::LongBreak, 'sequence_number' => 4]);

    $result = $this->action->execute($breakSession, $settings);

    expect($result['type'])->toBe(FocusSessionType::Work)
        ->and($result['sequence_number'])->toBe(5)
        ->and($result['duration_seconds'])->toBe($settings->work_duration_minutes * 60);
});

test('uses custom long break interval from settings', function (): void {
    $user = User::factory()->create();
    $settings = PomodoroSetting::create(array_merge(
        PomodoroSetting::defaults(),
        [
            'user_id' => $user->id,
            'long_break_after_pomodoros' => 3,
        ]
    ));

    // Sequence 3 should trigger long break with interval of 3
    $workSession = FocusSession::factory()
        ->for($user)
        ->work()
        ->create(['sequence_number' => 3, 'type' => FocusSessionType::Work]);

    $result = $this->action->execute($workSession, $settings);

    expect($result['type'])->toBe(FocusSessionType::LongBreak);
});
