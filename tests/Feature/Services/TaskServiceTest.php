<?php

use App\Enums\TaskRecurrenceType;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Carbon\Carbon;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertSoftDeleted;

it('creates a task in a transaction', function (): void {
    $user = User::factory()->create();

    $task = app(TaskService::class)->createTask($user, [
        'title' => 'My Task',
    ]);

    expect($task)->toBeInstanceOf(Task::class);
    expect($task->user_id)->toBe($user->id);

    assertDatabaseHas('tasks', [
        'id' => $task->id,
        'user_id' => $user->id,
        'title' => 'My Task',
    ]);
});

it('forces the provided user_id over attributes', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $task = app(TaskService::class)->createTask($user, [
        'title' => 'Task',
        'user_id' => $otherUser->id,
    ]);

    expect($task->user_id)->toBe($user->id);
    assertDatabaseHas('tasks', [
        'id' => $task->id,
        'user_id' => $user->id,
    ]);
});

it('updates and deletes a task', function (): void {
    $user = User::factory()->create();

    $task = app(TaskService::class)->createTask($user, [
        'title' => 'Before',
    ]);

    $updated = app(TaskService::class)->updateTask($task, [
        'title' => 'After',
        'user_id' => User::factory()->create()->id,
    ]);

    expect($updated->title)->toBe('After');
    expect($updated->user_id)->toBe($user->id);

    assertDatabaseHas('tasks', [
        'id' => $task->id,
        'title' => 'After',
    ]);

    $deleted = app(TaskService::class)->deleteTask($task);
    expect($deleted)->toBeTrue();

    assertSoftDeleted('tasks', [
        'id' => $task->id,
    ]);
});

it('keeps recurring tasks relevant even if their task dates change', function (): void {
    Carbon::setTestNow('2026-02-02 10:00:00');

    $task = Task::factory()->create([
        'start_datetime' => Carbon::parse('2026-02-01 09:00:00'),
        'end_datetime' => Carbon::parse('2026-02-01 10:00:00'),
    ]);

    RecurringTask::query()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-01-01 00:00:00'),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    $results = Task::query()
        ->with('recurringTask')
        ->relevantForDate(Carbon::parse('2026-02-02'))
        ->get();

    expect($results->pluck('id'))->toContain($task->id);
});
