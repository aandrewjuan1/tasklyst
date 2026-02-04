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

it('updateOrCreateRecurringTask creates recurrence when enabled', function (): void {
    $user = User::factory()->create();

    $task = app(TaskService::class)->createTask($user, [
        'title' => 'Task',
        'start_datetime' => now()->startOfDay()->addHours(9),
    ]);

    expect($task->recurringTask)->toBeNull();

    app(TaskService::class)->updateOrCreateRecurringTask($task, [
        'enabled' => true,
        'type' => 'daily',
        'interval' => 1,
        'daysOfWeek' => [],
    ]);

    $task->refresh()->load('recurringTask');
    expect($task->recurringTask)->not->toBeNull();
    expect($task->recurringTask->recurrence_type->value)->toBe('daily');
});

it('updateOrCreateRecurringTask deletes recurrence when disabled', function (): void {
    $user = User::factory()->create();

    $task = app(TaskService::class)->createTask($user, [
        'title' => 'Task',
        'start_datetime' => now()->startOfDay()->addHours(9),
        'recurrence' => [
            'enabled' => true,
            'type' => 'daily',
            'interval' => 1,
            'daysOfWeek' => [],
        ],
    ]);

    expect($task->recurringTask)->not->toBeNull();

    app(TaskService::class)->updateOrCreateRecurringTask($task, [
        'enabled' => false,
        'type' => null,
        'interval' => 1,
        'daysOfWeek' => [],
    ]);

    $task->refresh();
    expect($task->recurringTask)->toBeNull();
});

it('updateOrCreateRecurringTask updates existing recurrence', function (): void {
    $user = User::factory()->create();

    $task = app(TaskService::class)->createTask($user, [
        'title' => 'Task',
        'start_datetime' => now()->startOfDay()->addHours(9),
        'recurrence' => [
            'enabled' => true,
            'type' => 'daily',
            'interval' => 1,
            'daysOfWeek' => [],
        ],
    ]);

    $recurringTaskId = $task->recurringTask->id;

    app(TaskService::class)->updateOrCreateRecurringTask($task, [
        'enabled' => true,
        'type' => 'weekly',
        'interval' => 2,
        'daysOfWeek' => [1, 3, 5],
    ]);

    $task->refresh()->load('recurringTask');
    expect($task->recurringTask->recurrence_type->value)->toBe('weekly');
    expect($task->recurringTask->interval)->toBe(2);
    expect($task->recurringTask->days_of_week)->toBe('[1,3,5]');
});
