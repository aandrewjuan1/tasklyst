<?php

use App\Actions\FocusSession\StartFocusSessionAction;
use App\Enums\FocusSessionType;
use App\Enums\TaskStatus;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\TaskInstance;
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

test('recurring task with occurrence_date creates instance and leaves base task unchanged', function (): void {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create(['status' => TaskStatus::ToDo]);
    RecurringTask::factory()->create(['task_id' => $task->id]);
    $occurrenceDate = '2025-02-15';

    $session = $this->action->execute(
        $user,
        $task,
        FocusSessionType::Work,
        1500,
        now(),
        1,
        ['used_task_duration' => true],
        $occurrenceDate
    );

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::ToDo);

    $instance = TaskInstance::query()
        ->where('task_id', $task->id)
        ->whereDate('instance_date', $occurrenceDate)
        ->first();
    expect($instance)->not->toBeNull()
        ->and($instance->status)->toBe(TaskStatus::Doing);

    expect($session->payload)->toHaveKey('occurrence_date', $occurrenceDate);
});

test('recurring task without occurrence_date creates instance for started_at date and leaves base task unchanged', function (): void {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create(['status' => TaskStatus::ToDo]);
    RecurringTask::factory()->create(['task_id' => $task->id]);
    $startedAt = now();

    $session = $this->action->execute($user, $task, FocusSessionType::Work, 1500, $startedAt, 1, [], null);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::ToDo);

    $expectedDate = $startedAt->format('Y-m-d');
    $instance = TaskInstance::query()
        ->where('task_id', $task->id)
        ->whereDate('instance_date', $expectedDate)
        ->first();
    expect($instance)->not->toBeNull()
        ->and($instance->status)->toBe(TaskStatus::Doing);

    expect($session->payload)->toHaveKey('occurrence_date', $expectedDate);
});
