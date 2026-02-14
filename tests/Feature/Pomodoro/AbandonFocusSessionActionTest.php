<?php

use App\Actions\FocusSession\AbandonFocusSessionAction;
use App\Models\FocusSession;
use App\Models\User;

beforeEach(function (): void {
    $this->action = app(AbandonFocusSessionAction::class);
});

test('sets ended_at and completed false', function (): void {
    $user = User::factory()->create();
    $session = FocusSession::factory()->for($user)->inProgress()->create();

    $result = $this->action->execute($session);

    expect($result->ended_at)->not->toBeNull()
        ->and($result->completed)->toBeFalse();
});
