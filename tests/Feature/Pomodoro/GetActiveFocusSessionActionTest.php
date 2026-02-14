<?php

use App\Actions\FocusSession\GetActiveFocusSessionAction;
use App\Models\FocusSession;
use App\Models\User;

beforeEach(function (): void {
    $this->action = app(GetActiveFocusSessionAction::class);
});

test('returns null when user has no in-progress session', function (): void {
    $user = User::factory()->create();

    $result = $this->action->execute($user);

    expect($result)->toBeNull();
});

test('returns in-progress session when user has one', function (): void {
    $user = User::factory()->create();
    $session = FocusSession::factory()->for($user)->inProgress()->create();

    $result = $this->action->execute($user);

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($session->id)
        ->and($result->ended_at)->toBeNull()
        ->and($result->completed)->toBeFalse();
});

test('does not return completed session', function (): void {
    $user = User::factory()->create();
    FocusSession::factory()->for($user)->completed()->create();

    $result = $this->action->execute($user);

    expect($result)->toBeNull();
});
