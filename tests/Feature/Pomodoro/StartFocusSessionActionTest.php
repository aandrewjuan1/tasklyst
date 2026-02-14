<?php

use App\Actions\FocusSession\StartFocusSessionAction;
use App\Enums\FocusSessionType;
use App\Models\Task;
use App\Models\User;

beforeEach(function (): void {
    $this->action = app(StartFocusSessionAction::class);
});

test('creates work session with task', function (): void {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create();

    $session = $this->action->execute(
        $user,
        $task,
        FocusSessionType::Work,
        1500,
        now(),
        1,
        ['used_task_duration' => true]
    );

    expect($session->user_id)->toBe($user->id)
        ->and($session->focusable_type)->toBe($task->getMorphClass())
        ->and($session->focusable_id)->toBe($task->id)
        ->and($session->type)->toBe(FocusSessionType::Work)
        ->and($session->duration_seconds)->toBe(1500)
        ->and($session->completed)->toBeFalse()
        ->and($session->ended_at)->toBeNull()
        ->and($session->payload)->toBe(['used_task_duration' => true]);
});

test('creates break session without task', function (): void {
    $user = User::factory()->create();

    $session = $this->action->execute(
        $user,
        null,
        FocusSessionType::ShortBreak,
        300,
        now(),
        2,
        []
    );

    expect($session->focusable_type)->toBeNull()
        ->and($session->focusable_id)->toBeNull()
        ->and($session->type)->toBe(FocusSessionType::ShortBreak)
        ->and($session->sequence_number)->toBe(2);
});

test('abandons previous in-progress session when starting new one', function (): void {
    $user = User::factory()->create();
    $task1 = Task::factory()->for($user)->create();
    $task2 = Task::factory()->for($user)->create();

    $first = $this->action->execute($user, $task1, FocusSessionType::Work, 1500, now(), 1, []);
    expect($first->ended_at)->toBeNull();

    $second = $this->action->execute($user, $task2, FocusSessionType::Work, 1500, now(), 1, []);

    $first->refresh();
    expect($first->ended_at)->not->toBeNull()
        ->and($first->completed)->toBeFalse()
        ->and($second->id)->not->toBe($first->id)
        ->and($second->focusable_id)->toBe($task2->id);
});
