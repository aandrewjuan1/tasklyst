<?php

use App\Actions\Pomodoro\CompletePomodoroSessionAction;
use App\Enums\FocusSessionType;
use App\Models\FocusSession;
use App\Models\PomodoroSetting;
use App\Models\User;

beforeEach(function (): void {
    $this->action = app(CompletePomodoroSessionAction::class);
});

test('completes pomodoro work session and returns next break session info', function (): void {
    $user = User::factory()->create();
    $settings = PomodoroSetting::create(array_merge(
        PomodoroSetting::defaults(),
        [
            'user_id' => $user->id,
            'auto_start_break' => false,
        ]
    ));

    $session = FocusSession::factory()
        ->for($user)
        ->work()
        ->create([
            'type' => FocusSessionType::Work,
            'sequence_number' => 1,
            'payload' => ['focus_mode_type' => 'pomodoro'],
        ]);

    $result = $this->action->execute($session, now(), true, 0);

    expect($result['session']->completed)->toBeTrue()
        ->and($result['session']->ended_at)->not->toBeNull()
        ->and($result['next_session'])->not->toBeNull()
        ->and($result['next_session']['type'])->toBe(FocusSessionType::ShortBreak)
        ->and($result['next_session']['sequence_number'])->toBe(1)
        ->and($result['next_session']['auto_start'])->toBeFalse();
});

test('returns long break when at long break interval', function (): void {
    $user = User::factory()->create();
    $settings = PomodoroSetting::create(array_merge(
        PomodoroSetting::defaults(),
        [
            'user_id' => $user->id,
            'long_break_after_pomodoros' => 4,
        ]
    ));

    $session = FocusSession::factory()
        ->for($user)
        ->work()
        ->create([
            'type' => FocusSessionType::Work,
            'sequence_number' => 4,
            'payload' => ['focus_mode_type' => 'pomodoro'],
        ]);

    $result = $this->action->execute($session, now(), true, 0);

    expect($result['next_session']['type'])->toBe(FocusSessionType::LongBreak)
        ->and($result['next_session']['duration_seconds'])->toBe($settings->long_break_minutes * 60);
});

test('returns next work session after break', function (): void {
    $user = User::factory()->create();
    $settings = PomodoroSetting::create(array_merge(
        PomodoroSetting::defaults(),
        [
            'user_id' => $user->id,
            'auto_start_pomodoro' => true,
        ]
    ));

    // Create a completed work session so next will be 2
    FocusSession::factory()
        ->for($user)
        ->work()
        ->completed()
        ->create(['sequence_number' => 1, 'started_at' => now()]);

    $breakSession = FocusSession::factory()
        ->for($user)
        ->create([
            'type' => FocusSessionType::ShortBreak,
            'sequence_number' => 1,
            'payload' => ['focus_mode_type' => 'pomodoro'],
        ]);

    $result = $this->action->execute($breakSession, now(), true, 0);

    expect($result['next_session']['type'])->toBe(FocusSessionType::Work)
        ->and($result['next_session']['sequence_number'])->toBe(2)
        ->and($result['next_session']['auto_start'])->toBeTrue();
});

test('respects auto_start_break setting', function (): void {
    $user = User::factory()->create();
    $settings = PomodoroSetting::create(array_merge(
        PomodoroSetting::defaults(),
        [
            'user_id' => $user->id,
            'auto_start_break' => true,
        ]
    ));

    $session = FocusSession::factory()
        ->for($user)
        ->work()
        ->create([
            'type' => FocusSessionType::Work,
            'sequence_number' => 1,
            'payload' => ['focus_mode_type' => 'pomodoro'],
        ]);

    $result = $this->action->execute($session, now(), true, 0);

    expect($result['next_session']['auto_start'])->toBeTrue();
});

test('respects auto_start_pomodoro setting', function (): void {
    $user = User::factory()->create();
    $settings = PomodoroSetting::create(array_merge(
        PomodoroSetting::defaults(),
        [
            'user_id' => $user->id,
            'auto_start_pomodoro' => false,
        ]
    ));

    FocusSession::factory()
        ->for($user)
        ->work()
        ->completed()
        ->create(['sequence_number' => 1, 'started_at' => now()]);

    $breakSession = FocusSession::factory()
        ->for($user)
        ->create([
            'type' => FocusSessionType::ShortBreak,
            'sequence_number' => 1,
            'payload' => ['focus_mode_type' => 'pomodoro'],
        ]);

    $result = $this->action->execute($breakSession, now(), true, 0);

    expect($result['next_session']['auto_start'])->toBeFalse();
});

test('returns null next session when session is abandoned', function (): void {
    $user = User::factory()->create();
    PomodoroSetting::create(array_merge(
        PomodoroSetting::defaults(),
        ['user_id' => $user->id]
    ));

    $session = FocusSession::factory()
        ->for($user)
        ->work()
        ->create([
            'type' => FocusSessionType::Work,
            'sequence_number' => 1,
            'payload' => ['focus_mode_type' => 'pomodoro'],
        ]);

    $result = $this->action->execute($session, now(), false, 0);

    expect($result['session']->completed)->toBeFalse()
        ->and($result['next_session'])->toBeNull();
});

test('returns null next session when not a pomodoro session', function (): void {
    $user = User::factory()->create();
    PomodoroSetting::create(array_merge(
        PomodoroSetting::defaults(),
        ['user_id' => $user->id]
    ));

    $session = FocusSession::factory()
        ->for($user)
        ->work()
        ->create([
            'type' => FocusSessionType::Work,
            'sequence_number' => 1,
            'payload' => ['focus_mode_type' => 'countdown'], // Not pomodoro
        ]);

    $result = $this->action->execute($session, now(), true, 0);

    expect($result['next_session'])->toBeNull();
});

test('identifies pomodoro session by break type even without payload', function (): void {
    $user = User::factory()->create();
    $settings = PomodoroSetting::create(array_merge(
        PomodoroSetting::defaults(),
        ['user_id' => $user->id]
    ));

    $breakSession = FocusSession::factory()
        ->for($user)
        ->create([
            'type' => FocusSessionType::ShortBreak,
            'sequence_number' => 1,
            'payload' => null, // No focus_mode_type in payload
        ]);

    $result = $this->action->execute($breakSession, now(), true, 0);

    expect($result['next_session'])->not->toBeNull()
        ->and($result['next_session']['type'])->toBe(FocusSessionType::Work);
});
