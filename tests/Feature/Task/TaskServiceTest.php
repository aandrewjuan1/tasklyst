<?php

use App\Enums\CollaborationPermission;
use App\Enums\TaskRecurrenceType;
use App\Enums\TaskStatus;
use App\Models\RecurringTask;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskException;
use App\Models\TaskInstance;
use App\Models\User;
use App\Services\TaskService;
use Carbon\Carbon;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->service = app(TaskService::class);
});

test('create task sets user_id and minimal attributes', function (): void {
    $task = $this->service->createTask($this->user, [
        'title' => 'Minimal task',
        'status' => TaskStatus::ToDo->value,
    ]);

    expect($task)->toBeInstanceOf(Task::class)
        ->and($task->user_id)->toBe($this->user->id)
        ->and($task->title)->toBe('Minimal task')
        ->and($task->exists)->toBeTrue();
});

test('create task with tag ids attaches tags', function (): void {
    $tag1 = Tag::factory()->for($this->user)->create();
    $tag2 = Tag::factory()->for($this->user)->create();

    $task = $this->service->createTask($this->user, [
        'title' => 'Tagged task',
        'tagIds' => [$tag1->id, $tag2->id],
    ]);

    $task->load('tags');
    expect($task->tags->pluck('id')->all())->toEqualCanonicalizing([$tag1->id, $tag2->id]);
});

test('create task with recurrence enabled creates recurring task', function (): void {
    $task = $this->service->createTask($this->user, [
        'title' => 'Recurring task',
        'recurrence' => [
            'enabled' => true,
            'type' => TaskRecurrenceType::Weekly->value,
            'interval' => 2,
            'daysOfWeek' => [1, 3],
        ],
    ]);

    $task->load('recurringTask');
    expect($task->recurringTask)->not->toBeNull()
        ->and($task->recurringTask->recurrence_type->value)->toBe(TaskRecurrenceType::Weekly->value)
        ->and($task->recurringTask->interval)->toBe(2)
        ->and(json_decode($task->recurringTask->days_of_week, true))->toEqual([1, 3]);
});

test('create task without project has null project_id', function (): void {
    $task = $this->service->createTask($this->user, [
        'title' => 'No project task',
        'project_id' => null,
    ]);

    expect($task->project_id)->toBeNull();
});

test('update task updates attributes', function (): void {
    $task = Task::factory()->for($this->user)->create(['title' => 'Original']);

    $updated = $this->service->updateTask($task, ['title' => 'Updated title']);

    expect($updated->title)->toBe('Updated title')
        ->and($task->fresh()->title)->toBe('Updated title');
});

test('update task status to done sets completed_at', function (): void {
    $task = Task::factory()->for($this->user)->create([
        'status' => TaskStatus::ToDo,
        'completed_at' => null,
    ]);

    $this->service->updateTask($task, ['status' => TaskStatus::Done->value]);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Done)
        ->and($task->completed_at)->not->toBeNull();
});

test('update task status from done to doing clears completed_at', function (): void {
    $task = Task::factory()->for($this->user)->create([
        'status' => TaskStatus::Done,
        'completed_at' => now(),
    ]);

    $this->service->updateTask($task, ['status' => TaskStatus::Doing->value]);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Doing)
        ->and($task->completed_at)->toBeNull();
});

test('update task start and end datetime syncs to recurring task', function (): void {
    $task = Task::factory()->for($this->user)->create([
        'start_datetime' => null,
        'end_datetime' => null,
    ]);
    RecurringTask::factory()->create([
        'task_id' => $task->id,
        'start_datetime' => null,
        'end_datetime' => null,
    ]);

    $newStart = Carbon::parse('2025-03-01 09:00');
    $newEnd = Carbon::parse('2025-03-31 17:00');
    $this->service->updateTask($task, [
        'start_datetime' => $newStart,
        'end_datetime' => $newEnd,
    ]);

    $recurring = $task->recurringTask()->first();
    expect($recurring)->not->toBeNull()
        ->and($recurring->start_datetime->format('Y-m-d H:i'))->toBe($newStart->format('Y-m-d H:i'))
        ->and($recurring->end_datetime->format('Y-m-d H:i'))->toBe($newEnd->format('Y-m-d H:i'));
});

test('delete task soft deletes and boot removes related records', function (): void {
    $task = Task::factory()->for($this->user)->create();
    $recurring = RecurringTask::factory()->create(['task_id' => $task->id]);
    $collab = $task->collaborations()->create([
        'user_id' => User::factory()->create()->id,
        'permission' => CollaborationPermission::Edit,
    ]);
    $invitation = $task->collaborationInvitations()->create([
        'inviter_id' => $this->user->id,
        'invitee_email' => 'a@b.com',
        'permission' => CollaborationPermission::View,
        'status' => 'pending',
        'token' => \Illuminate\Support\Str::random(32),
    ]);

    $result = $this->service->deleteTask($task);

    expect($result)->toBeTrue();
    expect(Task::withTrashed()->find($task->id))->not->toBeNull()
        ->and(Task::withTrashed()->find($task->id)->trashed())->toBeTrue();
    expect(RecurringTask::find($recurring->id))->toBeNull();
    expect($collab->fresh())->toBeNull();
    expect($invitation->fresh())->toBeNull();
});

test('update or create recurring task creates when enabled with type', function (): void {
    $task = Task::factory()->for($this->user)->create();

    $this->service->updateOrCreateRecurringTask($task, [
        'enabled' => true,
        'type' => TaskRecurrenceType::Daily->value,
        'interval' => 1,
        'daysOfWeek' => [],
    ]);

    $task->load('recurringTask');
    expect($task->recurringTask)->not->toBeNull()
        ->and($task->recurringTask->recurrence_type->value)->toBe('daily');
});

test('update or create recurring task replaces existing when called again', function (): void {
    $task = Task::factory()->for($this->user)->create();
    $this->service->updateOrCreateRecurringTask($task, [
        'enabled' => true,
        'type' => TaskRecurrenceType::Daily->value,
        'interval' => 1,
        'daysOfWeek' => [],
    ]);
    $firstId = $task->recurringTask->id;

    $this->service->updateOrCreateRecurringTask($task, [
        'enabled' => true,
        'type' => TaskRecurrenceType::Weekly->value,
        'interval' => 1,
        'daysOfWeek' => [1],
    ]);

    $task->load('recurringTask');
    expect(RecurringTask::find($firstId))->toBeNull()
        ->and($task->recurringTask->recurrence_type->value)->toBe('weekly');
});

test('update or create recurring task disables by deleting when enabled false', function (): void {
    $task = Task::factory()->for($this->user)->create();
    $this->service->updateOrCreateRecurringTask($task, [
        'enabled' => true,
        'type' => TaskRecurrenceType::Daily->value,
        'interval' => 1,
        'daysOfWeek' => [],
    ]);
    $recurringId = $task->recurringTask->id;

    $this->service->updateOrCreateRecurringTask($task, ['enabled' => false, 'type' => null]);

    expect(RecurringTask::find($recurringId))->toBeNull();
});

test('update recurring occurrence status creates task instance', function (): void {
    $task = Task::factory()->for($this->user)->create();
    $recurring = RecurringTask::factory()->create([
        'task_id' => $task->id,
        'start_datetime' => Carbon::parse('2025-02-01'),
        'end_datetime' => Carbon::parse('2025-02-28'),
    ]);
    $date = Carbon::parse('2025-02-10');

    $instance = $this->service->updateRecurringOccurrenceStatus($task, $date, TaskStatus::Done);

    expect($instance)->toBeInstanceOf(TaskInstance::class)
        ->and($instance->recurring_task_id)->toBe($recurring->id)
        ->and($instance->instance_date->format('Y-m-d'))->toBe('2025-02-10')
        ->and($instance->status)->toBe(TaskStatus::Done)
        ->and($instance->completed_at)->not->toBeNull();
});

test('update recurring occurrence status updates existing instance', function (): void {
    $task = Task::factory()->for($this->user)->create();
    $recurring = RecurringTask::factory()->create(['task_id' => $task->id]);
    $date = Carbon::parse('2025-02-10');
    TaskInstance::factory()->create([
        'recurring_task_id' => $recurring->id,
        'task_id' => $task->id,
        'instance_date' => $date,
        'status' => TaskStatus::ToDo,
    ]);

    $instance = $this->service->updateRecurringOccurrenceStatus($task, $date, TaskStatus::Doing);

    expect($instance->status)->toBe(TaskStatus::Doing);
    expect(TaskInstance::where('recurring_task_id', $recurring->id)->whereDate('instance_date', $date)->count())->toBe(1);
});

test('get effective status for date returns base status for non recurring task', function (): void {
    $task = Task::factory()->for($this->user)->create(['status' => TaskStatus::Doing]);

    $status = $this->service->getEffectiveStatusForDate($task, Carbon::parse('2025-02-10'));

    expect($status)->toBe(TaskStatus::Doing);
});

test('get effective status for date returns instance status when instance exists', function (): void {
    $task = Task::factory()->for($this->user)->create(['status' => TaskStatus::ToDo]);
    $recurring = RecurringTask::factory()->create(['task_id' => $task->id]);
    $date = Carbon::parse('2025-02-10');
    TaskInstance::factory()->create([
        'recurring_task_id' => $recurring->id,
        'task_id' => $task->id,
        'instance_date' => $date,
        'status' => TaskStatus::Done,
    ]);
    $task->setRelation('instanceForDate', $task->recurringTask->taskInstances()->whereDate('instance_date', $date)->first());

    $task->load('recurringTask');
    $task->instanceForDate = $task->recurringTask->taskInstances()->whereDate('instance_date', $date)->first();
    $status = $this->service->getEffectiveStatusForDate($task, $date);

    expect($status)->toBe(TaskStatus::Done);
});

test('process recurring tasks for date filters and attaches instance and effective status', function (): void {
    $task = Task::factory()->for($this->user)->create();
    $recurring = RecurringTask::factory()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'start_datetime' => Carbon::parse('2025-02-01'),
        'end_datetime' => Carbon::parse('2025-02-28'),
    ]);
    $task->setRelation('recurringTask', $recurring);
    $date = Carbon::parse('2025-02-10');

    $result = $this->service->processRecurringTasksForDate(collect([$task]), $date);

    expect($result)->toHaveCount(1);
    $processed = $result->first();
    expect($processed->effectiveStatusForDate)->toBeInstanceOf(TaskStatus::class);
});

test('is task relevant for date returns true for non recurring task', function (): void {
    $task = Task::factory()->for($this->user)->create();

    $relevant = $this->service->isTaskRelevantForDate($task, Carbon::parse('2025-02-10'));

    expect($relevant)->toBeTrue();
});

test('is task relevant for date returns true when date is in recurring occurrences', function (): void {
    $task = Task::factory()->for($this->user)->create();
    RecurringTask::factory()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'start_datetime' => Carbon::parse('2025-02-01'),
        'end_datetime' => Carbon::parse('2025-02-28'),
    ]);
    $task->load('recurringTask');

    $relevant = $this->service->isTaskRelevantForDate($task, Carbon::parse('2025-02-10'));

    expect($relevant)->toBeTrue();
});

test('create task exception creates or updates exception', function (): void {
    $recurring = RecurringTask::factory()->create();
    $date = Carbon::parse('2025-02-10');

    $exception = $this->service->createTaskException($recurring, $date, true, null, $this->user);

    expect($exception)->toBeInstanceOf(TaskException::class)
        ->and($exception->recurring_task_id)->toBe($recurring->id)
        ->and($exception->exception_date->format('Y-m-d'))->toBe('2025-02-10')
        ->and($exception->is_deleted)->toBeTrue();
});
