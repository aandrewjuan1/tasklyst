<?php

use App\Actions\Pomodoro\GetPomodoroSequenceNumberAction;
use App\Enums\FocusSessionType;
use App\Models\FocusSession;
use App\Models\User;

beforeEach(function (): void {
    $this->action = app(GetPomodoroSequenceNumberAction::class);
});

test('returns 1 when user has no completed work sessions today', function (): void {
    $user = User::factory()->create();

    $sequence = $this->action->execute($user);

    expect($sequence)->toBe(1);
});

test('returns next sequence number based on completed work sessions today', function (): void {
    $user = User::factory()->create();

    // Create 3 completed work sessions today
    FocusSession::factory()
        ->for($user)
        ->work()
        ->completed()
        ->create(['started_at' => now()]);

    FocusSession::factory()
        ->for($user)
        ->work()
        ->completed()
        ->create(['started_at' => now()]);

    FocusSession::factory()
        ->for($user)
        ->work()
        ->completed()
        ->create(['started_at' => now()]);

    $sequence = $this->action->execute($user);

    expect($sequence)->toBe(4);
});

test('ignores incomplete work sessions', function (): void {
    $user = User::factory()->create();

    // Create incomplete work session
    FocusSession::factory()
        ->for($user)
        ->work()
        ->create(['completed' => false, 'started_at' => now()]);

    $sequence = $this->action->execute($user);

    expect($sequence)->toBe(1);
});

test('ignores break sessions', function (): void {
    $user = User::factory()->create();

    // Create completed break sessions
    FocusSession::factory()
        ->for($user)
        ->create(['type' => FocusSessionType::ShortBreak, 'completed' => true, 'started_at' => now()]);

    FocusSession::factory()
        ->for($user)
        ->create(['type' => FocusSessionType::LongBreak, 'completed' => true, 'started_at' => now()]);

    $sequence = $this->action->execute($user);

    expect($sequence)->toBe(1);
});

test('ignores sessions from other users', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    // Create completed work sessions for user2
    FocusSession::factory()
        ->for($user2)
        ->work()
        ->completed()
        ->create(['started_at' => now()]);

    $sequence = $this->action->execute($user1);

    expect($sequence)->toBe(1);
});

test('ignores sessions from previous days', function (): void {
    $user = User::factory()->create();

    // Create completed work session from yesterday
    FocusSession::factory()
        ->for($user)
        ->work()
        ->completed()
        ->create(['started_at' => now()->subDay()]);

    // Create completed work session from today
    FocusSession::factory()
        ->for($user)
        ->work()
        ->completed()
        ->create(['started_at' => now()]);

    $sequence = $this->action->execute($user);

    expect($sequence)->toBe(2);
});
