<?php

use App\Enums\CollaborationPermission;
use App\Enums\TaskComplexity;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Collaboration;
use App\Models\CollaborationInvitation;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->collaborator = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('scope for user returns tasks owned by the user', function (): void {
    $owned = Task::factory()->for($this->owner)->create(['title' => 'Owned task']);
    Task::factory()->for($this->otherUser)->create(['title' => 'Other task']);

    $tasks = Task::query()->forUser($this->owner->id)->get();

    expect($tasks)->toHaveCount(1)
        ->and($tasks->first()->id)->toBe($owned->id);
});

test('scope for user returns tasks where user is collaborator', function (): void {
    $task = Task::factory()->for($this->owner)->create(['title' => 'Shared task']);
    Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    $tasks = Task::query()->forUser($this->collaborator->id)->get();

    expect($tasks)->toHaveCount(1)
        ->and($tasks->first()->id)->toBe($task->id);
});

test('scope for user does not return other users tasks without collaboration', function (): void {
    Task::factory()->for($this->owner)->create(['title' => 'Owner only task']);

    $tasks = Task::query()->forUser($this->collaborator->id)->get();

    expect($tasks)->toHaveCount(0);
});

test('scope incomplete excludes tasks with completed_at set', function (): void {
    Task::factory()->for($this->owner)->create(['completed_at' => null]);
    Task::factory()->for($this->owner)->create(['completed_at' => now()]);

    $tasks = Task::query()->forUser($this->owner->id)->incomplete()->get();

    expect($tasks)->toHaveCount(1)
        ->and($tasks->first()->completed_at)->toBeNull();
});

test('scope relevant for date includes tasks with no dates', function (): void {
    $task = Task::factory()->for($this->owner)->create([
        'start_datetime' => null,
        'end_datetime' => null,
    ]);

    $date = Carbon::parse('2025-02-10');
    $tasks = Task::query()->forUser($this->owner->id)->relevantForDate($date)->get();

    expect($tasks->contains('id', $task->id))->toBeTrue();
});

test('scope relevant for date includes tasks when date is before or on end date', function (): void {
    $endDate = Carbon::parse('2025-02-15')->endOfDay();
    $task = Task::factory()->for($this->owner)->create([
        'start_datetime' => null,
        'end_datetime' => $endDate,
    ]);

    $date = Carbon::parse('2025-02-10');
    $tasks = Task::query()->forUser($this->owner->id)->relevantForDate($date)->get();

    expect($tasks->contains('id', $task->id))->toBeTrue();
});

test('scope relevant for date includes tasks when date is within start and end range', function (): void {
    $start = Carbon::parse('2025-02-08')->startOfDay();
    $end = Carbon::parse('2025-02-12')->endOfDay();
    $task = Task::factory()->for($this->owner)->create([
        'start_datetime' => $start,
        'end_datetime' => $end,
    ]);

    $date = Carbon::parse('2025-02-10');
    $tasks = Task::query()->forUser($this->owner->id)->relevantForDate($date)->get();

    expect($tasks->contains('id', $task->id))->toBeTrue();
});

test('scope overdue returns tasks with end_datetime before given date', function (): void {
    $pastDue = Task::factory()->for($this->owner)->create([
        'end_datetime' => Carbon::parse('2025-02-05'),
        'completed_at' => null,
    ]);
    Task::factory()->for($this->owner)->create([
        'end_datetime' => Carbon::parse('2025-02-15'),
        'completed_at' => null,
    ]);

    $asOf = Carbon::parse('2025-02-10');
    $tasks = Task::query()->forUser($this->owner->id)->overdue($asOf)->get();

    expect($tasks)->toHaveCount(1)
        ->and($tasks->first()->id)->toBe($pastDue->id);
});

test('scope order by priority orders urgent then high then medium then low', function (): void {
    Task::factory()->for($this->owner)->create(['priority' => TaskPriority::Low]);
    Task::factory()->for($this->owner)->create(['priority' => TaskPriority::Urgent]);
    Task::factory()->for($this->owner)->create(['priority' => TaskPriority::Medium]);
    Task::factory()->for($this->owner)->create(['priority' => TaskPriority::High]);

    $tasks = Task::query()->forUser($this->owner->id)->orderByPriority()->get();

    expect($tasks->pluck('priority')->map(fn ($p) => $p->value)->values()->all())
        ->toBe(['urgent', 'high', 'medium', 'low']);
});

test('scope with no date returns only tasks with null start and end datetime', function (): void {
    $noDate = Task::factory()->for($this->owner)->create([
        'start_datetime' => null,
        'end_datetime' => null,
    ]);
    Task::factory()->for($this->owner)->create([
        'start_datetime' => now(),
        'end_datetime' => null,
    ]);

    $tasks = Task::query()->forUser($this->owner->id)->withNoDate()->get();

    expect($tasks)->toHaveCount(1)
        ->and($tasks->first()->id)->toBe($noDate->id);
});

test('scope high priority returns only high and urgent tasks', function (): void {
    Task::factory()->for($this->owner)->create(['priority' => TaskPriority::Urgent]);
    Task::factory()->for($this->owner)->create(['priority' => TaskPriority::High]);
    Task::factory()->for($this->owner)->create(['priority' => TaskPriority::Medium]);

    $tasks = Task::query()->forUser($this->owner->id)->highPriority()->get();

    expect($tasks)->toHaveCount(2);
    expect($tasks->pluck('priority')->map(fn ($p) => $p->value)->unique()->sort()->values()->all())
        ->toEqual(['high', 'urgent']);
});

test('scope by priority filters by priority value', function (): void {
    Task::factory()->for($this->owner)->create(['priority' => TaskPriority::High]);
    Task::factory()->for($this->owner)->create(['priority' => TaskPriority::Low]);

    $tasks = Task::query()->forUser($this->owner->id)->byPriority(TaskPriority::High->value)->get();

    expect($tasks)->toHaveCount(1)
        ->and($tasks->first()->priority)->toBe(TaskPriority::High);
});

test('scope by status filters by status value', function (): void {
    Task::factory()->for($this->owner)->create(['status' => TaskStatus::Doing]);
    Task::factory()->for($this->owner)->create(['status' => TaskStatus::Done]);

    $tasks = Task::query()->forUser($this->owner->id)->byStatus(TaskStatus::Doing->value)->get();

    expect($tasks)->toHaveCount(1)
        ->and($tasks->first()->status)->toBe(TaskStatus::Doing);
});

test('scope by complexity filters by complexity value', function (): void {
    Task::factory()->for($this->owner)->create(['complexity' => TaskComplexity::Moderate]);
    Task::factory()->for($this->owner)->create(['complexity' => TaskComplexity::Simple]);

    $tasks = Task::query()->forUser($this->owner->id)->byComplexity(TaskComplexity::Moderate->value)->get();

    expect($tasks)->toHaveCount(1)
        ->and($tasks->first()->complexity)->toBe(TaskComplexity::Moderate);
});

test('scope due soon returns tasks due within given days from date', function (): void {
    $from = Carbon::parse('2025-02-10')->startOfDay();
    $dueIn3Days = Task::factory()->for($this->owner)->create([
        'end_datetime' => $from->copy()->addDays(3),
    ]);
    Task::factory()->for($this->owner)->create([
        'end_datetime' => $from->copy()->addDays(10),
    ]);

    $tasks = Task::query()->forUser($this->owner->id)->dueSoon($from, 7)->get();

    expect($tasks)->toHaveCount(1)
        ->and($tasks->first()->id)->toBe($dueIn3Days->id);
});

test('deleting task cascades to collaborations and collaboration invitations but keeps recurring task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $collab = Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::Edit,
    ]);
    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $this->owner->id,
        'invitee_user_id' => $this->collaborator->id,
    ]);
    $recurring = RecurringTask::factory()->create(['task_id' => $task->id]);

    $task->delete();

    expect(Collaboration::find($collab->id))->toBeNull()
        ->and(CollaborationInvitation::find($invitation->id))->toBeNull()
        ->and(RecurringTask::find($recurring->id))->not->toBeNull();
});

test('property to column maps startDatetime and endDatetime to snake_case', function (): void {
    expect(Task::propertyToColumn('startDatetime'))->toBe('start_datetime')
        ->and(Task::propertyToColumn('endDatetime'))->toBe('end_datetime')
        ->and(Task::propertyToColumn('title'))->toBe('title');
});

test('get property value for update returns correct value for enums and dates', function (): void {
    $task = Task::factory()->for($this->owner)->create([
        'status' => TaskStatus::Doing,
        'priority' => TaskPriority::High,
        'complexity' => TaskComplexity::Moderate,
        'start_datetime' => $start = Carbon::parse('2025-02-10 09:00'),
        'end_datetime' => $end = Carbon::parse('2025-02-11 17:00'),
        'title' => 'Test Task',
    ]);

    expect($task->getPropertyValueForUpdate('status'))->toBe(TaskStatus::Doing->value)
        ->and($task->getPropertyValueForUpdate('priority'))->toBe(TaskPriority::High->value)
        ->and($task->getPropertyValueForUpdate('complexity'))->toBe(TaskComplexity::Moderate->value)
        ->and($task->getPropertyValueForUpdate('startDatetime'))->toEqual($start)
        ->and($task->getPropertyValueForUpdate('endDatetime'))->toEqual($end)
        ->and($task->getPropertyValueForUpdate('title'))->toBe('Test Task');
});

test('task can belong to parent task and have subtasks', function (): void {
    $parent = Task::factory()->for($this->owner)->create(['title' => 'Parent']);
    $child1 = Task::factory()->for($this->owner)->create(['title' => 'Child 1', 'parent_task_id' => $parent->id]);
    $child2 = Task::factory()->for($this->owner)->create(['title' => 'Child 2', 'parent_task_id' => $parent->id]);

    expect($parent->subtasks)->toHaveCount(2)
        ->and($child1->parentTask->id)->toBe($parent->id)
        ->and($child2->parentTask->id)->toBe($parent->id)
        ->and($parent->parentTask)->toBeNull();
});

test('scope root tasks returns only top-level tasks', function (): void {
    $root = Task::factory()->for($this->owner)->create(['title' => 'Root', 'parent_task_id' => null]);
    Task::factory()->for($this->owner)->create(['title' => 'Sub', 'parent_task_id' => $root->id]);

    $rootTasks = Task::query()->forUser($this->owner->id)->rootTasks()->get();

    expect($rootTasks)->toHaveCount(1)
        ->and($rootTasks->first()->id)->toBe($root->id);
});

test('property to column maps parent task id project id and event id', function (): void {
    expect(Task::propertyToColumn('parentTaskId'))->toBe('parent_task_id')
        ->and(Task::propertyToColumn('projectId'))->toBe('project_id')
        ->and(Task::propertyToColumn('eventId'))->toBe('event_id');
});
