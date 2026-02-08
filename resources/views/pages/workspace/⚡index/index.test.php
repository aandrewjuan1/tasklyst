<?php

use App\Enums\CollaborationPermission;
use App\Enums\TaskRecurrenceType;
use App\Enums\EventRecurrenceType;
use App\Enums\TaskStatus;
use App\Models\Collaboration;
use App\Models\Event;
use App\Models\Project;
use App\Models\RecurringEvent;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

it('renders the workspace page', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->assertStatus(200)
        ->assertSee('Workspace')
        ->assertSee('Your tasks, projects, and events');
});

it('has empty data when no user is authenticated', function (): void {
    $user = User::factory()->create();

    $task = Task::factory()->for($user)->create();
    $project = Project::factory()->for($user)->create();
    $event = Event::factory()->for($user)->create();

    $today = now()->toDateString();

    Livewire::test('pages::workspace.index')
        ->assertSet('selectedDate', $today)
        ->assertDontSee($task->title)
        ->assertDontSee($project->name)
        ->assertDontSee($event->title);
});

it('shows today by default and allows navigation', function (): void {
    $user = User::factory()->create();

    $today = now()->toDateString();
    $tomorrow = Carbon::parse($today)->addDay()->toDateString();
    $yesterday = Carbon::parse($today)->subDay()->toDateString();

    $component = Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->assertSet('selectedDate', $today)
        ->assertSee(Carbon::parse($today)->translatedFormat('D, M j, Y'));

    $component
        ->set('selectedDate', $tomorrow)
        ->assertSet('selectedDate', $tomorrow)
        ->assertSee(Carbon::parse($tomorrow)->translatedFormat('D, M j, Y'))
        ->assertSee('Today');

    $component
        ->set('selectedDate', $yesterday)
        ->assertSet('selectedDate', $yesterday)
        ->assertSee(Carbon::parse($yesterday)->translatedFormat('D, M j, Y'))
        ->assertSee('Today');

    $component
        ->set('selectedDate', $today)
        ->assertSet('selectedDate', $today)
        ->assertSee(Carbon::parse($today)->translatedFormat('D, M j, Y'));
});

it('shows tasks, projects, and events for the selected date', function (): void {
    $user = User::factory()->create();

    $date = Carbon::create(2026, 1, 27);

    $project = Project::factory()->for($user)->create([
        'start_datetime' => $date->copy()->startOfDay(),
    ]);

    $task = Task::factory()->for($user)->for($project)->create([
        'start_datetime' => $date->copy()->startOfDay(),
        'completed_at' => null,
    ]);

    $event = Event::factory()->for($user)->create([
        'start_datetime' => $date->copy()->startOfDay(),
        'end_datetime' => $date->copy()->startOfDay()->addHour(),
        'status' => \App\Enums\EventStatus::Scheduled,
    ]);

    $formattedDate = $date->translatedFormat('D, M j, Y');

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $date->toDateString())
        ->assertSee($formattedDate)
        ->assertSee($project->name)
        ->assertSee($task->title)
        ->assertSee($event->title);
});

it('shows overdue tasks and events in the main list with overdue badge', function (): void {
    $user = User::factory()->create();

    $overdueTask = Task::factory()->for($user)->create([
        'title' => 'Overdue Task Title',
        'end_datetime' => now()->subDays(2),
        'completed_at' => null,
    ]);

    $overdueEvent = Event::factory()->for($user)->create([
        'title' => 'Overdue Event Title',
        'end_datetime' => now()->subDay(),
        'status' => \App\Enums\EventStatus::Scheduled,
    ]);

    $this->actingAs($user)
        ->get(route('workspace'))
        ->assertOk()
        ->assertSee(__('Overdue'))
        ->assertSee('Overdue Task Title')
        ->assertSee('Overdue Event Title');
});

it('only marks items as overdue when end/due date is before today, not selected date', function (): void {
    $user = User::factory()->create();
    $today = now()->startOfDay();
    $tomorrow = $today->copy()->addDay();

    $taskDueToday = Task::factory()->for($user)->create([
        'title' => 'Task Due Today',
        'end_datetime' => $today->copy()->setTime(18, 0),
        'completed_at' => null,
    ]);

    $overdueTask = Task::factory()->for($user)->create([
        'title' => 'Actually Overdue Task',
        'end_datetime' => $today->copy()->subDay(),
        'completed_at' => null,
    ]);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $tomorrow->toDateString())
        ->assertSee('Actually Overdue Task')
        ->assertDontSee('Task Due Today');
});

it('can create a task from the workspace component', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('createTask', [
            'title' => 'Inline created task',
            'status' => 'to_do',
            'priority' => 'medium',
            'complexity' => 'moderate',
            'duration' => 60,
            'startDatetime' => null,
            'endDatetime' => null,
            'projectId' => null,
        ])
        ->assertSee('Inline created task')
        ->assertDispatched('toast', type: 'success', message: __('Added :title.', ['title' => '“Inline created task”']), icon: 'plus-circle');
});

it('creates task with project association', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()->for($user)->create([
        'start_datetime' => now()->startOfDay(),
    ]);

    $date = now()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $date)
        ->call('createTask', [
            'title' => 'Task with Project',
            'status' => 'to_do',
            'priority' => 'medium',
            'complexity' => 'moderate',
            'duration' => 60,
            'startDatetime' => null,
            'endDatetime' => null,
            'projectId' => $project->id,
        ])
        ->assertSee('Task with Project')
        ->assertDispatched('toast', type: 'success', message: __('Added :title.', ['title' => '“Task with Project”']), icon: 'plus-circle');

    $this->assertDatabaseHas('tasks', [
        'title' => 'Task with Project',
        'user_id' => $user->id,
        'project_id' => $project->id,
    ]);
});

it('rejects createTask when projectId references a project the user cannot access', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherProject = Project::factory()->for($otherUser)->create();

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('createTask', [
            'title' => 'Task with unauthorized project',
            'status' => 'to_do',
            'priority' => 'medium',
            'complexity' => 'moderate',
            'duration' => 60,
            'startDatetime' => null,
            'endDatetime' => null,
            'projectId' => $otherProject->id,
        ])
        ->assertDispatched('toast', type: 'error', message: __('Project not found.'));

    $this->assertDatabaseMissing('tasks', [
        'title' => 'Task with unauthorized project',
        'user_id' => $user->id,
        'project_id' => $otherProject->id,
    ]);
});

it('rejects createTask when projectId references a project the user can view but not update', function (): void {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner)->create();

    Collaboration::create([
        'collaboratable_type' => Project::class,
        'collaboratable_id' => $project->id,
        'user_id' => $user->id,
        'permission' => CollaborationPermission::View,
    ]);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('createTask', [
            'title' => 'Task with view-only project',
            'status' => 'to_do',
            'priority' => 'medium',
            'complexity' => 'moderate',
            'duration' => 60,
            'startDatetime' => null,
            'endDatetime' => null,
            'projectId' => $project->id,
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('tasks', [
        'title' => 'Task with view-only project',
        'user_id' => $user->id,
        'project_id' => $project->id,
    ]);
});

it('creates task with datetime', function (): void {
    $user = User::factory()->create();

    $startDatetime = now()->startOfDay()->addHours(9)->toIso8601String();
    $endDatetime = now()->startOfDay()->addHours(10)->toIso8601String();
    $date = now()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $date)
        ->call('createTask', [
            'title' => 'Task with Datetime',
            'status' => 'to_do',
            'priority' => 'medium',
            'complexity' => 'moderate',
            'duration' => 60,
            'startDatetime' => $startDatetime,
            'endDatetime' => $endDatetime,
            'projectId' => null,
        ])
        ->assertSee('Task with Datetime')
        ->assertDispatched('toast', type: 'success', message: __('Added :title.', ['title' => '“Task with Datetime”']), icon: 'plus-circle');

    $this->assertDatabaseHas('tasks', [
        'title' => 'Task with Datetime',
        'user_id' => $user->id,
    ]);
});

it('can create a project from the workspace component', function (): void {
    $user = User::factory()->create();

    $date = now()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $date)
        ->call('createProject', [
            'name' => 'New Project',
            'description' => null,
            'startDatetime' => null,
            'endDatetime' => null,
        ])
        ->assertSee('New Project')
        ->assertDispatched('toast', type: 'success', message: __('Added :name.', ['name' => '"New Project"']), icon: 'plus-circle');

    $this->assertDatabaseHas('projects', [
        'name' => 'New Project',
        'user_id' => $user->id,
    ]);
});

it('deletes a project through the workspace component', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()
        ->for($user)
        ->create([
            'name' => 'Project To Delete',
            'start_datetime' => now()->startOfDay()->addHours(9),
            'end_datetime' => now()->startOfDay()->addHours(10),
        ]);

    $date = now()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $date)
        ->call('deleteProject', $project->id)
        ->assertDispatched('toast', type: 'success', message: __('Deleted :name.', ['name' => '"Project To Delete"']), icon: 'trash');

    $this->assertSoftDeleted('projects', [
        'id' => $project->id,
    ]);
});

it('can create an event from the workspace component', function (): void {
    $user = User::factory()->create();

    $date = now()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $date)
        ->call('createEvent', [
            'title' => 'Inline created event',
            'status' => 'scheduled',
            'startDatetime' => null,
            'endDatetime' => null,
            'allDay' => false,
        ])
        ->assertSee('Inline created event')
        ->assertDispatched('toast', type: 'success', message: __('Added :title.', ['title' => '“Inline created event”']), icon: 'plus-circle');

    $this->assertDatabaseHas('events', [
        'title' => 'Inline created event',
        'user_id' => $user->id,
    ]);
});

it('can create an all-day event from the workspace component', function (): void {
    $user = User::factory()->create();

    $date = now()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $date)
        ->call('createEvent', [
            'title' => 'Inline all-day event',
            'status' => 'scheduled',
            'startDatetime' => null,
            'endDatetime' => null,
            'allDay' => true,
        ])
        ->assertSee('Inline all-day event')
        ->assertDispatched('toast', type: 'success', message: __('Added :title.', ['title' => '“Inline all-day event”']), icon: 'plus-circle');

    $this->assertDatabaseHas('events', [
        'title' => 'Inline all-day event',
        'user_id' => $user->id,
        'all_day' => true,
    ]);
});

it('deletes an event through the workspace component', function (): void {
    $user = User::factory()->create();

    $event = Event::factory()
        ->for($user)
        ->create([
            'title' => 'Event To Delete',
            'start_datetime' => now()->startOfDay()->addHours(9),
            'end_datetime' => now()->startOfDay()->addHours(10),
            'all_day' => false,
        ]);

    $date = now()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $date)
        ->call('deleteEvent', $event->id)
        ->assertDispatched('toast', type: 'success', message: __('Deleted :title.', ['title' => '“Event To Delete”']), icon: 'trash');

    $this->assertSoftDeleted('events', [
        'id' => $event->id,
    ]);
});

it('deletes a task through the workspace component', function (): void {
    $user = User::factory()->create();

    $task = Task::factory()
        ->for($user)
        ->create([
            'title' => 'Task To Delete',
            'start_datetime' => now()->startOfDay()->addHours(9),
            'completed_at' => null,
        ]);

    $date = now()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $date)
        ->call('deleteTask', $task->id)
        ->assertDispatched('toast', type: 'success', message: __('Deleted :title.', ['title' => '“Task To Delete”']), icon: 'trash');

    $this->assertSoftDeleted('tasks', [
        'id' => $task->id,
    ]);
});

it('updates a task property through the workspace component', function (): void {
    $user = User::factory()->create();

    $task = Task::factory()
        ->for($user)
        ->create([
            'title' => 'Task To Update',
            'status' => 'to_do',
            'priority' => 'low',
            'start_datetime' => now()->startOfDay()->addHours(9),
            'completed_at' => null,
        ]);

    $date = now()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $date)
        ->call('updateTaskProperty', $task->id, 'status', 'doing')
        ->assertDispatched('toast', type: 'success', message: __(':property: :from → :to.', ['property' => __('Status'), 'from' => 'To Do', 'to' => 'Doing']).' — '.__('Task').': ' . '“Task To Update”', icon: 'check-circle');

    $task->refresh();
    expect($task->status->value)->toBe('doing');
    expect($task->priority->value)->toBe('low');
});

it('updates task priority and complexity via updateTaskProperty', function (): void {
    $user = User::factory()->create();

    $task = Task::factory()
        ->for($user)
        ->create([
            'title' => 'Task',
            'priority' => 'medium',
            'complexity' => 'moderate',
            'completed_at' => null,
        ]);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateTaskProperty', $task->id, 'priority', 'high')
        ->assertDispatched('toast', type: 'success', message: __(':property: :from → :to.', ['property' => __('Priority'), 'from' => 'Medium', 'to' => 'High']).' — '.__('Task').': ' . '“Task”', icon: 'bolt');

    $task->refresh();
    expect($task->priority->value)->toBe('high');

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateTaskProperty', $task->id, 'complexity', 'complex')
        ->assertDispatched('toast', type: 'success', message: __(':property: :from → :to.', ['property' => __('Complexity'), 'from' => 'Moderate', 'to' => 'Complex']).' — '.__('Task').': ' . '“Task”', icon: 'squares-2x2');

    $task->refresh();
    expect($task->complexity->value)->toBe('complex');
});

it('updates task title via updateTaskProperty', function (): void {
    $user = User::factory()->create();

    $task = Task::factory()
        ->for($user)
        ->create([
            'title' => 'Task Old Title',
            'completed_at' => null,
        ]);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateTaskProperty', $task->id, 'title', 'Task New Title')
        ->assertDispatched('toast', type: 'success', message: __(':property: :from → :to.', ['property' => __('Title'), 'from' => '“Task Old Title”', 'to' => '“Task New Title”']).' — '.__('Task').': ' . '“Task New Title”', icon: 'pencil-square');

    $task->refresh();
    expect($task->title)->toBe('Task New Title');
});

it('updates task recurrence via updateTaskProperty', function (): void {
    $user = User::factory()->create();

    $task = Task::factory()
        ->for($user)
        ->create([
            'title' => 'Task',
            'start_datetime' => now()->startOfDay()->addHours(9),
            'completed_at' => null,
        ]);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateTaskProperty', $task->id, 'recurrence', [
            'enabled' => true,
            'type' => 'daily',
            'interval' => 1,
            'daysOfWeek' => [],
        ])
        ->assertDispatched('toast', type: 'success', message: __(':property: :from → :to.', ['property' => __('Recurring'), 'from' => __('Off'), 'to' => 'DAILY']).' — '.__('Task').': ' . '“Task”', icon: 'arrow-path');

    $task->refresh()->load('recurringTask');
    expect($task->recurringTask)->not->toBeNull();
    expect($task->recurringTask->recurrence_type)->toBe(TaskRecurrenceType::Daily);
    expect($task->recurringTask->interval)->toBe(1);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateTaskProperty', $task->id, 'recurrence', [
            'enabled' => true,
            'type' => 'weekly',
            'interval' => 2,
            'daysOfWeek' => [1, 3],
        ])
        ->assertDispatched('toast', type: 'success', message: __(':property: :from → :to.', ['property' => __('Recurring'), 'from' => 'DAILY', 'to' => 'EVERY 2 WEEKS (MON, WED)']).' — '.__('Task').': ' . '“Task”', icon: 'arrow-path');

    $task->refresh()->load('recurringTask');
    expect($task->recurringTask->recurrence_type)->toBe(TaskRecurrenceType::Weekly);
    expect($task->recurringTask->interval)->toBe(2);
    expect($task->recurringTask->days_of_week)->toBe('[1,3]');

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateTaskProperty', $task->id, 'recurrence', [
            'enabled' => false,
            'type' => null,
            'interval' => 1,
            'daysOfWeek' => [],
        ])
        ->assertDispatched('toast', type: 'success', message: __(':property: :from → :to.', ['property' => __('Recurring'), 'from' => 'EVERY 2 WEEKS (MON, WED)', 'to' => __('Off')]).' — '.__('Task').': ' . '“Task”', icon: 'arrow-path');

    $task->refresh();
    expect($task->recurringTask)->toBeNull();
});

it('updates base task status when marking recurring task as done via updateTaskProperty', function (): void {
    $user = User::factory()->create();
    $date = now()->toDateString();

    $task = Task::factory()
        ->for($user)
        ->create([
            'title' => 'Recurring Task',
            'status' => 'to_do',
            'start_datetime' => now()->startOfDay()->addHours(9),
            'completed_at' => null,
        ]);

    RecurringTask::query()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => now()->startOfDay(),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $date)
        ->call('updateTaskProperty', $task->id, 'status', 'done')
        ->assertDispatched('toast', type: 'success');

    $task->refresh();
    expect($task->status->value)->toBe('done');
    expect($task->completed_at)->not->toBeNull();
});

it('shows recurring task for selected date in list', function (): void {
    Carbon::setTestNow('2026-02-06 10:00:00');
    $user = User::factory()->create();
    $date = now()->toDateString();

    $task = Task::factory()
        ->for($user)
        ->create([
            'title' => 'Recurring Task Today',
            'status' => 'to_do',
            'start_datetime' => now()->startOfDay()->addHours(9),
            'completed_at' => null,
        ]);

    RecurringTask::query()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => now()->startOfDay(),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    $task->load(['recurringTask', 'project', 'event', 'tags', 'collaborations']);
    $task->effectiveStatusForDate = app(\App\Services\TaskService::class)->getEffectiveStatusForDate($task, \Carbon\Carbon::parse($date));
    $tasks = collect([$task]);

    Livewire::actingAs($user)
        ->test('pages::workspace.list', [
            'selectedDate' => $date,
            'projects' => collect(),
            'events' => collect(),
            'tasks' => $tasks,
            'overdue' => collect(),
            'tags' => collect(),
        ])
        ->assertSee('Recurring Task Today');
});

it('updates base task status when updating recurring task status to doing via updateTaskProperty without occurrence date', function (): void {
    $user = User::factory()->create();
    $date = now()->toDateString();

    $task = Task::factory()
        ->for($user)
        ->create([
            'title' => 'Recurring Task',
            'status' => 'to_do',
            'start_datetime' => now()->startOfDay()->addHours(9),
            'completed_at' => null,
        ]);

    RecurringTask::query()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => now()->startOfDay(),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $date)
        ->call('updateTaskProperty', $task->id, 'status', 'doing')
        ->assertDispatched('toast', type: 'success');

    $task->refresh();
    expect($task->status->value)->toBe('doing');
});

it('creates TaskInstance when updating recurring task status with occurrence date', function (): void {
    Carbon::setTestNow('2026-02-06 10:00:00');
    $user = User::factory()->create();
    $date = '2026-02-06';

    $task = Task::factory()
        ->for($user)
        ->create([
            'title' => 'Recurring Task',
            'status' => TaskStatus::ToDo,
            'start_datetime' => Carbon::parse('2026-02-01 09:00:00'),
            'completed_at' => null,
        ]);

    RecurringTask::query()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-02-01 00:00:00'),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $date)
        ->call('updateTaskProperty', $task->id, 'status', 'done', false, $date)
        ->assertDispatched('toast', type: 'success');

    $task->refresh();
    expect($task->status->value)->toBe('to_do');

    $instance = \App\Models\TaskInstance::query()
        ->where('recurring_task_id', $task->recurringTask->id)
        ->whereDate('instance_date', $date)
        ->first();

    expect($instance)->not->toBeNull();
    expect($instance->status->value)->toBe('done');
});

it('rejects updateTaskProperty for invalid property', function (): void {
    $user = User::factory()->create();

    $task = Task::factory()->for($user)->create(['completed_at' => null, 'project_id' => null]);
    $originalTitle = $task->title;

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateTaskProperty', $task->id, 'projectId', 123)
        ->assertDispatched('toast', type: 'error', message: __('Invalid property for update.'));

    $task->refresh();
    expect($task->title)->toBe($originalTitle);
});

it('rejects updateTaskProperty for invalid value', function (): void {
    $user = User::factory()->create();

    $task = Task::factory()->for($user)->create(['completed_at' => null]);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateTaskProperty', $task->id, 'status', 'invalid_status')
        ->assertDispatched('toast', type: 'error');

    $task->refresh();
    expect($task->status->value)->not->toBe('invalid_status');
});

it('rejects updateTaskProperty when end date is before start date', function (): void {
    $user = User::factory()->create();

    $start = now()->startOfDay()->addHours(10);
    $end = now()->startOfDay()->addHours(14);
    $task = Task::factory()
        ->for($user)
        ->create([
            'start_datetime' => $start,
            'end_datetime' => $end,
            'completed_at' => null,
        ]);

    $endBeforeStart = now()->startOfDay()->addHours(8)->format('Y-m-d\TH:i:s');

    $result = Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateTaskProperty', $task->id, 'endDatetime', $endBeforeStart);

    $result->assertDispatched('toast', type: 'error', message: __('End date must be the same as or after the start date.'));

    $task->refresh();
    expect($task->end_datetime->format('Y-m-d H:i'))->toBe($end->format('Y-m-d H:i'));
});

it('rejects updateTaskProperty when task not found', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateTaskProperty', 99999, 'status', 'doing')
        ->assertDispatched('toast', type: 'error', message: __('Task not found.'));
});

it('updates an event property through the workspace component', function (): void {
    $user = User::factory()->create();

    $event = Event::factory()
        ->for($user)
        ->create([
            'title' => 'Event To Update',
            'status' => 'scheduled',
            'start_datetime' => now()->startOfDay()->addHours(9),
        ]);

    $date = now()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $date)
        ->call('updateEventProperty', $event->id, 'status', 'completed')
        ->assertDispatched('toast', type: 'success', message: __(':property: :from → :to.', ['property' => __('Status'), 'from' => 'Scheduled', 'to' => 'Completed']).' — '.__('Event').': ' . '“Event To Update”', icon: 'check-circle');

    $event->refresh();
    expect($event->status->value)->toBe('completed');
});

it('updates event title via updateEventProperty', function (): void {
    $user = User::factory()->create();

    $event = Event::factory()
        ->for($user)
        ->create([
            'title' => 'Event Old Title',
            'status' => 'scheduled',
        ]);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateEventProperty', $event->id, 'title', 'Event New Title')
        ->assertDispatched('toast', type: 'success', message: __(':property: :from → :to.', ['property' => __('Title'), 'from' => '“Event Old Title”', 'to' => '“Event New Title”']).' — '.__('Event').': ' . '“Event New Title”', icon: 'pencil-square');

    $event->refresh();
    expect($event->title)->toBe('Event New Title');
});

it('rejects updateEventProperty for invalid property', function (): void {
    $user = User::factory()->create();

    $event = Event::factory()->for($user)->create(['description' => null]);
    $originalTitle = $event->title;

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateEventProperty', $event->id, 'projectId', 123)
        ->assertDispatched('toast', type: 'error', message: __('Invalid property for update.'));

    $event->refresh();
    expect($event->title)->toBe($originalTitle);
});

it('updates event recurrence via updateEventProperty', function (): void {
    $user = User::factory()->create();

    $event = Event::factory()
        ->for($user)
        ->create([
            'title' => 'Event',
            'start_datetime' => now()->startOfDay()->addHours(9),
        ]);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateEventProperty', $event->id, 'recurrence', [
            'enabled' => true,
            'type' => 'daily',
            'interval' => 1,
            'daysOfWeek' => [],
        ])
        ->assertDispatched('toast', type: 'success', message: __(':property: :from → :to.', ['property' => __('Recurring'), 'from' => __('Off'), 'to' => 'DAILY']).' — '.__('Event').': ' . '“Event”', icon: 'arrow-path');

    $event->refresh()->load('recurringEvent');
    expect($event->recurringEvent)->not->toBeNull();
    expect($event->recurringEvent->recurrence_type)->toBe(EventRecurrenceType::Daily);
    expect($event->recurringEvent->interval)->toBe(1);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateEventProperty', $event->id, 'recurrence', [
            'enabled' => true,
            'type' => 'weekly',
            'interval' => 2,
            'daysOfWeek' => [1, 3],
        ])
        ->assertDispatched('toast', type: 'success', message: __(':property: :from → :to.', ['property' => __('Recurring'), 'from' => 'DAILY', 'to' => 'EVERY 2 WEEKS (MON, WED)']).' — '.__('Event').': ' . '“Event”', icon: 'arrow-path');

    $event->refresh()->load('recurringEvent');
    expect($event->recurringEvent->recurrence_type)->toBe(EventRecurrenceType::Weekly);
    expect($event->recurringEvent->interval)->toBe(2);
    expect($event->recurringEvent->days_of_week)->toBe('[1,3]');

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateEventProperty', $event->id, 'recurrence', [
            'enabled' => false,
            'type' => null,
            'interval' => 1,
            'daysOfWeek' => [],
        ])
        ->assertDispatched('toast', type: 'success', message: __(':property: :from → :to.', ['property' => __('Recurring'), 'from' => 'EVERY 2 WEEKS (MON, WED)', 'to' => __('Off')]).' — '.__('Event').': ' . '“Event”', icon: 'arrow-path');

    $event->refresh();
    expect($event->recurringEvent)->toBeNull();
});

it('updates a project name property through the workspace component', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()
        ->for($user)
        ->create([
            'name' => 'Project Old Name',
            'start_datetime' => now()->startOfDay(),
        ]);

    $date = now()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $date)
        ->call('updateProjectProperty', $project->id, 'name', 'Project New Name')
        ->assertDispatched('toast');

    $project->refresh();
    expect($project->name)->toBe('Project New Name');
});

it('rejects updateProjectProperty for invalid property', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()
        ->for($user)
        ->create([
            'name' => 'Project Name',
            'start_datetime' => now()->startOfDay(),
        ]);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateProjectProperty', $project->id, 'title', 'Hacked')
        ->assertDispatched('toast', type: 'error', message: __('Invalid property for update.'));

    $project->refresh();
    expect($project->name)->toBe('Project Name');
});

it('rejects updateEventProperty when event not found', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateEventProperty', 99999, 'status', 'completed')
        ->assertDispatched('toast', type: 'error', message: __('Event not found.'));
});

it('rejects updateTaskProperty when user cannot update task', function (): void {
    $user = User::factory()->create();
    $owner = User::factory()->create();

    $task = Task::factory()->for($owner)->create(['completed_at' => null, 'status' => 'to_do']);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateTaskProperty', $task->id, 'status', 'doing')
        ->assertDispatched('toast', type: 'error', message: __('Task not found.'));

    $task->refresh();
    expect($task->status->value)->toBe('to_do');
});

it('only shows tasks the user owns or collaborates on', function (): void {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $date = Carbon::create(2026, 1, 27)->startOfDay();

    $ownedTask = Task::factory()->for($user)->create([
        'start_datetime' => $date,
        'completed_at' => null,
    ]);

    $collaboratorTask = Task::factory()->for($owner)->create([
        'start_datetime' => $date,
        'completed_at' => null,
    ]);

    $hiddenTask = Task::factory()->for($stranger)->create([
        'start_datetime' => $date,
        'completed_at' => null,
    ]);

    Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $collaboratorTask->id,
        'user_id' => $user->id,
        'permission' => CollaborationPermission::View,
    ]);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $date->toDateString())
        ->assertSee($ownedTask->title)
        ->assertSee($collaboratorTask->title)
        ->assertDontSee($hiddenTask->title);
});

it('dispatches error toast when event validation fails', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('createEvent', [
            'title' => '',
            'status' => 'scheduled',
            'startDatetime' => null,
            'endDatetime' => null,
            'allDay' => false,
        ])
        ->assertDispatched('toast', type: 'error', message: __('Please fix the event details and try again.'));
});

it('dispatches error toast when task validation fails', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('createTask', [
            'title' => '',
            'status' => 'to_do',
            'priority' => 'medium',
            'complexity' => 'moderate',
            'duration' => 60,
            'startDatetime' => null,
            'endDatetime' => null,
            'projectId' => null,
        ])
        ->assertDispatched('toast', type: 'error', message: __('Please fix the task details and try again.'));
});

it('filters workspace by item type showing only tasks when setFilter itemType is tasks', function (): void {
    $user = User::factory()->create();
    $date = Carbon::create(2026, 1, 27);

    $task = Task::factory()->for($user)->create([
        'title' => 'Visible Task',
        'start_datetime' => $date->copy()->startOfDay(),
        'completed_at' => null,
    ]);

    Event::factory()->for($user)->create([
        'title' => 'Hidden Event',
        'start_datetime' => $date->copy()->startOfDay(),
        'end_datetime' => $date->copy()->startOfDay()->addHour(),
        'status' => \App\Enums\EventStatus::Scheduled,
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $date->toDateString())
        ->call('setFilter', 'itemType', 'tasks')
        ->assertSet('filterItemType', 'tasks');

    expect($component->get('tasks'))->toHaveCount(1);
    expect($component->get('events'))->toHaveCount(0);
    expect($component->get('projects'))->toHaveCount(0);
});

it('filters workspace by item type showing only events when setFilter itemType is events', function (): void {
    $user = User::factory()->create();
    $date = Carbon::create(2026, 1, 27);

    Task::factory()->for($user)->create([
        'title' => 'Hidden Task',
        'start_datetime' => $date->copy()->startOfDay(),
        'completed_at' => null,
    ]);

    Event::factory()->for($user)->create([
        'title' => 'Visible Event',
        'start_datetime' => $date->copy()->startOfDay(),
        'end_datetime' => $date->copy()->startOfDay()->addHour(),
        'status' => \App\Enums\EventStatus::Scheduled,
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $date->toDateString())
        ->call('setFilter', 'itemType', 'events')
        ->assertSet('filterItemType', 'events');

    expect($component->get('tasks'))->toHaveCount(0);
    expect($component->get('events'))->toHaveCount(1);
    expect($component->get('projects'))->toHaveCount(0);
});

it('filters workspace by item type showing only projects when setFilter itemType is projects', function (): void {
    $user = User::factory()->create();
    $date = Carbon::create(2026, 1, 27);

    Project::factory()->for($user)->create([
        'name' => 'Visible Project',
        'start_datetime' => $date->copy()->startOfDay(),
    ]);

    Task::factory()->for($user)->create([
        'title' => 'Hidden Task',
        'start_datetime' => $date->copy()->startOfDay(),
        'completed_at' => null,
    ]);

    Event::factory()->for($user)->create([
        'title' => 'Hidden Event',
        'start_datetime' => $date->copy()->startOfDay(),
        'end_datetime' => $date->copy()->startOfDay()->addHour(),
        'status' => \App\Enums\EventStatus::Scheduled,
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $date->toDateString())
        ->call('setFilter', 'itemType', 'projects')
        ->assertSet('filterItemType', 'projects');

    expect($component->get('tasks'))->toHaveCount(0);
    expect($component->get('events'))->toHaveCount(0);
    expect($component->get('projects'))->toHaveCount(1);
});

it('shows all item types when clearFilter itemType is called', function (): void {
    $user = User::factory()->create();
    $date = Carbon::create(2026, 1, 27);

    $project = Project::factory()->for($user)->create([
        'start_datetime' => $date->copy()->startOfDay(),
    ]);

    Task::factory()->for($user)->for($project)->create([
        'title' => 'Task Title',
        'start_datetime' => $date->copy()->startOfDay(),
        'completed_at' => null,
    ]);

    Event::factory()->for($user)->create([
        'title' => 'Event Title',
        'start_datetime' => $date->copy()->startOfDay(),
        'end_datetime' => $date->copy()->startOfDay()->addHour(),
        'status' => \App\Enums\EventStatus::Scheduled,
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $date->toDateString())
        ->call('setFilter', 'itemType', 'tasks')
        ->assertSet('filterItemType', 'tasks')
        ->call('clearFilter', 'itemType')
        ->assertSet('filterItemType', null);

    expect($component->get('tasks'))->toHaveCount(1);
    expect($component->get('events'))->toHaveCount(1);
    expect($component->get('projects'))->toHaveCount(1);
});

it('increments listRefresh when item type filter changes', function (): void {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test('pages::workspace.index');

    $initialRefresh = $component->get('listRefresh');

    $component->call('setFilter', 'itemType', 'tasks');
    expect($component->get('listRefresh'))->toBe($initialRefresh + 1);

    $component->call('setFilter', 'itemType', 'events');
    expect($component->get('listRefresh'))->toBe($initialRefresh + 2);
});

it('applies item type filter from URL query parameter', function (): void {
    $user = User::factory()->create();
    $date = Carbon::create(2026, 1, 27);

    $task = Task::factory()->for($user)->create([
        'title' => 'Task From URL',
        'start_datetime' => $date->copy()->startOfDay(),
        'completed_at' => null,
    ]);

    Event::factory()->for($user)->create([
        'title' => 'Hidden Event',
        'start_datetime' => $date->copy()->startOfDay(),
        'end_datetime' => $date->copy()->startOfDay()->addHour(),
        'status' => \App\Enums\EventStatus::Scheduled,
    ]);

    Livewire::actingAs($user)
        ->withQueryParams(['type' => 'tasks'])
        ->test('pages::workspace.index')
        ->set('selectedDate', $date->toDateString())
        ->assertSet('filterItemType', 'tasks')
        ->assertSee('Task From URL')
        ->assertDontSee('Hidden Event');
});

it('filters tasks by complexity when setFilter taskComplexity is called', function (): void {
    $user = User::factory()->create();
    $date = Carbon::create(2026, 1, 27);

    $simpleTask = Task::factory()->for($user)->create([
        'title' => 'Simple Task',
        'complexity' => \App\Enums\TaskComplexity::Simple,
        'start_datetime' => $date->copy()->startOfDay(),
        'completed_at' => null,
    ]);

    Task::factory()->for($user)->create([
        'title' => 'Complex Task',
        'complexity' => \App\Enums\TaskComplexity::Complex,
        'start_datetime' => $date->copy()->startOfDay(),
        'completed_at' => null,
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $date->toDateString())
        ->call('setFilter', 'taskComplexity', 'simple')
        ->assertSet('filterTaskComplexity', 'simple');

    expect($component->get('tasks'))->toHaveCount(1);
    expect($component->get('tasks')->first()->title)->toBe('Simple Task');
});

it('filters tasks and events by tag when setFilter tagIds is called', function (): void {
    $user = User::factory()->create();
    $date = Carbon::create(2026, 1, 27);

    $tagWork = \App\Models\Tag::factory()->for($user)->create(['name' => 'work']);
    $tagPersonal = \App\Models\Tag::factory()->for($user)->create(['name' => 'personal']);

    $taskWithWork = Task::factory()->for($user)->create([
        'title' => 'Task With Work Tag',
        'start_datetime' => $date->copy()->startOfDay(),
        'completed_at' => null,
    ]);
    $taskWithWork->tags()->attach($tagWork);

    Task::factory()->for($user)->create([
        'title' => 'Task With Personal Tag',
        'start_datetime' => $date->copy()->startOfDay(),
        'completed_at' => null,
    ])->tags()->attach($tagPersonal);

    $eventWithWork = Event::factory()->for($user)->create([
        'title' => 'Event With Work Tag',
        'start_datetime' => $date->copy()->startOfDay(),
        'end_datetime' => $date->copy()->startOfDay()->addHour(),
        'status' => \App\Enums\EventStatus::Scheduled,
    ]);
    $eventWithWork->tags()->attach($tagWork);

    $component = Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $date->toDateString())
        ->call('setFilter', 'tagIds', [$tagWork->id]);

    expect($component->get('tasks'))->toHaveCount(1);
    expect($component->get('tasks')->first()->title)->toBe('Task With Work Tag');
    expect($component->get('events'))->toHaveCount(1);
    expect($component->get('events')->first()->title)->toBe('Event With Work Tag');
});

it('filters by recurring when setFilter recurring is recurring', function (): void {
    $user = User::factory()->create();
    $date = Carbon::create(2026, 1, 27);

    $recurringTask = Task::factory()->for($user)->create([
        'title' => 'Recurring Task',
        'start_datetime' => $date->copy()->startOfDay(),
        'completed_at' => null,
    ]);

    \App\Models\RecurringTask::query()->create([
        'task_id' => $recurringTask->id,
        'recurrence_type' => \App\Enums\TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => $date->copy()->startOfDay(),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    Task::factory()->for($user)->create([
        'title' => 'One-time Task',
        'start_datetime' => $date->copy()->startOfDay(),
        'completed_at' => null,
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $date->toDateString())
        ->call('setFilter', 'recurring', 'recurring');

    expect($component->get('tasks'))->toHaveCount(1);
    expect($component->get('tasks')->first()->title)->toBe('Recurring Task');
});

it('clearAllFilters resets all filter properties', function (): void {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('setFilter', 'taskComplexity', 'simple')
        ->call('setFilter', 'tagIds', [1])
        ->call('clearAllFilters');

    expect($component->get('filterTaskComplexity'))->toBeNull();
    expect($component->get('filterTagIds'))->toBeNull();
});
