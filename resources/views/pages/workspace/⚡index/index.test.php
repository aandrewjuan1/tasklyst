<?php

use App\Enums\CollaborationPermission;
use App\Models\Collaboration;
use App\Models\Event;
use App\Models\Project;
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
        ->assertDispatched('toast', type: 'success', message: __('Task created.'));
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
        ->assertDispatched('toast', type: 'success', message: __('Task created.'));

    $this->assertDatabaseHas('tasks', [
        'title' => 'Task with Project',
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
        ->assertDispatched('toast', type: 'success', message: __('Task created.'));

    $this->assertDatabaseHas('tasks', [
        'title' => 'Task with Datetime',
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
        ->assertDispatched('toast', type: 'success', message: __('Project deleted.'));

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
        ->assertDispatched('toast', type: 'success', message: __('Event created.'));

    $this->assertDatabaseHas('events', [
        'title' => 'Inline created event',
        'user_id' => $user->id,
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
        ->assertDispatched('toast', type: 'success', message: __('Event deleted.'));

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
        ->assertDispatched('toast', type: 'success', message: __('Task deleted.'));

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
        ->assertDispatched('toast', type: 'success', message: __('Task updated.'));

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
        ->assertDispatched('toast', type: 'success', message: __('Task updated.'));

    $task->refresh();
    expect($task->priority->value)->toBe('high');

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateTaskProperty', $task->id, 'complexity', 'complex')
        ->assertDispatched('toast', type: 'success', message: __('Task updated.'));

    $task->refresh();
    expect($task->complexity->value)->toBe('complex');
});

it('rejects updateTaskProperty for invalid property', function (): void {
    $user = User::factory()->create();

    $task = Task::factory()->for($user)->create(['completed_at' => null]);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateTaskProperty', $task->id, 'title', 'Hacked')
        ->assertDispatched('toast', type: 'error', message: __('Invalid property for update.'));

    $task->refresh();
    expect($task->title)->not->toBe('Hacked');
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
        ->assertDispatched('toast', type: 'success', message: __('Event updated.'));

    $event->refresh();
    expect($event->status->value)->toBe('completed');
});

it('rejects updateEventProperty for invalid property', function (): void {
    $user = User::factory()->create();

    $event = Event::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateEventProperty', $event->id, 'title', 'Hacked')
        ->assertDispatched('toast', type: 'error', message: __('Invalid property for update.'));

    $event->refresh();
    expect($event->title)->not->toBe('Hacked');
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
        ->assertForbidden();

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
