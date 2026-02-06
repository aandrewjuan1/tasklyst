<?php

use App\Enums\TaskRecurrenceType;
use App\Enums\TaskStatus;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\TaskException;
use App\Models\TaskInstance;
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

it('updateTask syncs start_datetime and end_datetime to RecurringTask when present', function (): void {
    $task = app(TaskService::class)->createTask(User::factory()->create(), [
        'title' => 'Recurring Task',
        'start_datetime' => Carbon::parse('2026-02-01 09:00:00'),
        'end_datetime' => Carbon::parse('2026-02-28 17:00:00'),
        'recurrence' => [
            'enabled' => true,
            'type' => 'daily',
            'interval' => 1,
            'daysOfWeek' => [],
        ],
    ]);

    $recurring = $task->recurringTask;
    expect($recurring->start_datetime->toDateTimeString())->toBe('2026-02-01 09:00:00');
    expect($recurring->end_datetime->toDateTimeString())->toBe('2026-02-28 17:00:00');

    app(TaskService::class)->updateTask($task, [
        'start_datetime' => Carbon::parse('2026-03-01 10:00:00'),
        'end_datetime' => Carbon::parse('2026-03-31 18:00:00'),
    ]);

    $recurring->refresh();
    expect($recurring->start_datetime->toDateTimeString())->toBe('2026-03-01 10:00:00');
    expect($recurring->end_datetime->toDateTimeString())->toBe('2026-03-31 18:00:00');
});

it('deleteTask deletes recurring task and task instances', function (): void {
    Carbon::setTestNow('2026-02-06 10:00:00');

    $task = app(TaskService::class)->createTask(User::factory()->create(), [
        'title' => 'Recurring Task',
        'start_datetime' => now()->startOfDay()->addHours(9),
        'recurrence' => [
            'enabled' => true,
            'type' => 'daily',
            'interval' => 1,
            'daysOfWeek' => [],
        ],
    ]);

    $recurringTaskId = $task->recurringTask->id;
    TaskInstance::query()->create([
        'recurring_task_id' => $recurringTaskId,
        'task_id' => $task->id,
        'instance_date' => '2026-02-06',
        'status' => TaskStatus::Done,
    ]);

    app(TaskService::class)->deleteTask($task);

    assertSoftDeleted('tasks', ['id' => $task->id]);
    expect(RecurringTask::query()->find($recurringTaskId))->toBeNull();
    expect(TaskInstance::query()->where('recurring_task_id', $recurringTaskId)->count())->toBe(0);
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

it('completeRecurringOccurrence creates or updates TaskInstance', function (): void {
    Carbon::setTestNow('2026-02-06 10:00:00');

    $task = Task::factory()->create([
        'start_datetime' => Carbon::parse('2026-02-01 09:00:00'),
    ]);
    RecurringTask::query()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-01-01 00:00:00'),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    $instance = app(TaskService::class)->completeRecurringOccurrence($task, Carbon::parse('2026-02-06'));

    expect($instance)->toBeInstanceOf(TaskInstance::class);
    expect($instance->recurring_task_id)->toBe($task->recurringTask->id);
    expect($instance->instance_date->format('Y-m-d'))->toBe('2026-02-06');
    expect($instance->status->value)->toBe('done');
    expect($instance->completed_at)->not->toBeNull();
    expect($instance->task_id)->toBe($task->id);

    assertDatabaseHas('task_instances', [
        'recurring_task_id' => $task->recurringTask->id,
        'status' => 'done',
    ]);
});

it('completeRecurringOccurrence throws when task has no recurring task', function (): void {
    $task = Task::factory()->create();

    app(TaskService::class)->completeRecurringOccurrence($task, Carbon::parse('2026-02-06'));
})->throws(\InvalidArgumentException::class);

it('updateRecurringOccurrenceStatus creates or updates instance with any status', function (): void {
    Carbon::setTestNow('2026-02-06 10:00:00');

    $task = Task::factory()->create([
        'start_datetime' => Carbon::parse('2026-02-01 09:00:00'),
        'status' => TaskStatus::ToDo,
    ]);
    RecurringTask::query()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-01-01 00:00:00'),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    $instance = app(TaskService::class)->updateRecurringOccurrenceStatus($task, Carbon::parse('2026-02-06'), TaskStatus::Doing);

    expect($instance->status->value)->toBe('doing');
    expect($instance->completed_at)->toBeNull();

    $instance = app(TaskService::class)->updateRecurringOccurrenceStatus($task, Carbon::parse('2026-02-06'), TaskStatus::ToDo);

    expect($instance->status->value)->toBe('to_do');
    expect($instance->completed_at)->toBeNull();

    $instance = app(TaskService::class)->updateRecurringOccurrenceStatus($task, Carbon::parse('2026-02-06'), TaskStatus::Done);

    expect($instance->status->value)->toBe('done');
    expect($instance->completed_at)->not->toBeNull();
});

it('updateRecurringOccurrenceStatus updates existing instance instead of creating duplicate', function (): void {
    Carbon::setTestNow('2026-02-06 10:00:00');

    $task = Task::factory()->create([
        'start_datetime' => Carbon::parse('2026-02-01 09:00:00'),
        'status' => TaskStatus::ToDo,
    ]);
    RecurringTask::query()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-01-01 00:00:00'),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    $instance1 = app(TaskService::class)->updateRecurringOccurrenceStatus($task, Carbon::parse('2026-02-06'), TaskStatus::Doing);
    $instance2 = app(TaskService::class)->updateRecurringOccurrenceStatus($task, Carbon::parse('2026-02-06'), TaskStatus::Done);

    expect($instance1->id)->toBe($instance2->id);
    expect($instance2->status->value)->toBe('done');

    $count = TaskInstance::query()
        ->where('recurring_task_id', $task->recurringTask->id)
        ->whereDate('instance_date', '2026-02-06')
        ->count();

    expect($count)->toBe(1);
});

it('getEffectiveStatusForDate returns instance status when instance exists', function (): void {
    Carbon::setTestNow('2026-02-06 10:00:00');

    $task = Task::factory()->create([
        'status' => TaskStatus::ToDo,
    ]);
    $recurring = RecurringTask::query()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-01-01 00:00:00'),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    $effectiveStatus = app(TaskService::class)->getEffectiveStatusForDate($task->load('recurringTask'), Carbon::parse('2026-02-06'));
    expect($effectiveStatus)->toBe(TaskStatus::ToDo);

    TaskInstance::query()->create([
        'recurring_task_id' => $recurring->id,
        'task_id' => $task->id,
        'instance_date' => '2026-02-06',
        'status' => TaskStatus::Done,
    ]);

    $effectiveStatus = app(TaskService::class)->getEffectiveStatusForDate($task->load('recurringTask'), Carbon::parse('2026-02-06'));
    expect($effectiveStatus)->toBe(TaskStatus::Done);

    $effectiveStatus = app(TaskService::class)->getEffectiveStatusForDate($task->load('recurringTask'), Carbon::parse('2026-02-07'));
    expect($effectiveStatus)->toBe(TaskStatus::ToDo);
});

it('getEffectiveStatusForDate returns ToDo for recurring task with no instance regardless of base status', function (): void {
    Carbon::setTestNow('2026-02-06 10:00:00');

    $task = Task::factory()->create(['status' => TaskStatus::Doing]);
    RecurringTask::query()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-01-01 00:00:00'),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    $effectiveStatus = app(TaskService::class)->getEffectiveStatusForDate($task->load('recurringTask'), Carbon::parse('2026-02-06'));

    expect($effectiveStatus)->toBe(TaskStatus::ToDo);
});

it('getEffectiveStatusForDate returns base task status for non-recurring task', function (): void {
    $task = Task::factory()->create(['status' => TaskStatus::Doing]);

    $effectiveStatus = app(TaskService::class)->getEffectiveStatusForDate($task, Carbon::parse('2026-02-06'));

    expect($effectiveStatus)->toBe(TaskStatus::Doing);
});

it('isTaskRelevantForDate shows recurring task even when instance is done', function (): void {
    Carbon::setTestNow('2026-02-06 10:00:00');

    $task = Task::factory()->create([
        'start_datetime' => Carbon::parse('2026-02-01 09:00:00'),
    ]);
    RecurringTask::query()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-01-01 00:00:00'),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    TaskInstance::query()->create([
        'recurring_task_id' => $task->recurringTask->id,
        'task_id' => $task->id,
        'instance_date' => '2026-02-06',
        'status' => TaskStatus::Done,
        'completed_at' => now(),
    ]);

    $taskService = app(TaskService::class);
    expect($taskService->isTaskRelevantForDate($task->load('recurringTask'), Carbon::parse('2026-02-06')))->toBeTrue();
});

it('getOccurrencesForDateRange returns expanded dates', function (): void {
    $task = Task::factory()->create([
        'start_datetime' => Carbon::parse('2026-02-01 09:00:00'),
    ]);
    $recurring = RecurringTask::query()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-02-01 00:00:00'),
        'end_datetime' => Carbon::parse('2026-02-05 23:59:59'),
        'days_of_week' => null,
    ]);

    $dates = app(TaskService::class)->getOccurrencesForDateRange(
        $recurring,
        Carbon::parse('2026-02-01'),
        Carbon::parse('2026-02-05')
    );

    expect($dates)->toHaveCount(5);
    expect($dates[0]->format('Y-m-d'))->toBe('2026-02-01');
    expect($dates[4]->format('Y-m-d'))->toBe('2026-02-05');
});

it('getOccurrencesForDateRange excludes dates with is_deleted exception', function (): void {
    $task = Task::factory()->create([
        'start_datetime' => Carbon::parse('2026-02-01 09:00:00'),
    ]);
    $recurring = RecurringTask::query()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-02-01 00:00:00'),
        'end_datetime' => Carbon::parse('2026-02-05 23:59:59'),
        'days_of_week' => null,
    ]);
    TaskException::query()->create([
        'recurring_task_id' => $recurring->id,
        'exception_date' => Carbon::parse('2026-02-03'),
        'is_deleted' => true,
        'replacement_instance_id' => null,
    ]);

    $dates = app(TaskService::class)->getOccurrencesForDateRange(
        $recurring,
        Carbon::parse('2026-02-01'),
        Carbon::parse('2026-02-05')
    );

    expect($dates)->toHaveCount(4);
    expect(collect($dates)->map(fn ($d) => $d->format('Y-m-d'))->toArray())->not->toContain('2026-02-03');
});

it('createTaskException creates or updates exception', function (): void {
    $user = User::factory()->create();
    $task = Task::factory()->create([
        'start_datetime' => Carbon::parse('2026-02-01 09:00:00'),
    ]);
    $recurring = RecurringTask::query()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-01-01 00:00:00'),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    $exception = app(TaskService::class)->createTaskException(
        $recurring,
        Carbon::parse('2026-02-10'),
        true,
        null,
        $user
    );

    expect($exception)->toBeInstanceOf(TaskException::class);
    expect($exception->recurring_task_id)->toBe($recurring->id);
    expect($exception->exception_date->format('Y-m-d'))->toBe('2026-02-10');
    expect($exception->is_deleted)->toBeTrue();
    expect($exception->created_by)->toBe($user->id);

    assertDatabaseHas('task_exceptions', [
        'recurring_task_id' => $recurring->id,
        'is_deleted' => true,
    ]);
});
