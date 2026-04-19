<?php

use App\Enums\EventStatus;
use App\Enums\TaskStatus;
use App\Models\Event;
use App\Models\Project;
use App\Models\SchoolClass;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Livewire::flushState();
});

test('workspace task focus with agenda_focus omits show filter and preserves search shell', function (): void {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create([
        'title' => 'Agenda Link Task',
        'status' => TaskStatus::ToDo,
        'end_datetime' => now()->addHours(3),
        'completed_at' => null,
    ]);

    Livewire::actingAs($user)
        ->withQueryParams([
            'task' => (string) $task->id,
            'date' => now()->toDateString(),
            'view' => 'kanban',
            'agenda_focus' => '1',
        ])
        ->test('pages::workspace.index')
        ->assertSet('viewMode', 'list')
        ->assertSet('filterItemType', null)
        ->assertSet('focusTaskId', $task->id);
});

test('workspace task focus query forces list view and renders list item anchor', function (): void {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create([
        'title' => 'Deep Link Task',
        'status' => TaskStatus::ToDo,
        'end_datetime' => now()->addHours(3),
        'completed_at' => null,
    ]);

    Livewire::actingAs($user)
        ->withQueryParams([
            'task' => (string) $task->id,
            'date' => now()->toDateString(),
            'view' => 'kanban',
        ])
        ->test('pages::workspace.index')
        ->assertSet('viewMode', 'list')
        ->assertSet('filterItemType', 'tasks')
        ->assertSet('focusTaskId', $task->id);

    $this->actingAs($user)->get(route('workspace', [
        'date' => now()->toDateString(),
        'view' => 'kanban',
        'type' => 'events',
        'task' => $task->id,
    ]))
        ->assertSuccessful()
        ->assertSee('id="workspace-item-task-'.$task->id.'"', false);
});

test('workspace event focus query forces list view and renders list item anchor', function (): void {
    $user = User::factory()->create();
    $event = Event::factory()->for($user)->create([
        'title' => 'Deep Link Event',
        'status' => EventStatus::Scheduled,
        'start_datetime' => now()->addHour(),
        'end_datetime' => now()->addHours(2),
        'all_day' => false,
    ]);

    Livewire::actingAs($user)
        ->withQueryParams([
            'event' => (string) $event->id,
            'date' => now()->toDateString(),
            'view' => 'kanban',
        ])
        ->test('pages::workspace.index')
        ->assertSet('viewMode', 'list')
        ->assertSet('filterItemType', 'events')
        ->assertSet('focusEventId', $event->id);
});

test('workspace project focus query forces list view and projects filter', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create([
        'name' => 'Deep Link Project',
        'start_datetime' => now()->subDay(),
        'end_datetime' => now()->addMonth(),
    ]);

    Livewire::actingAs($user)
        ->withQueryParams([
            'project' => (string) $project->id,
            'date' => now()->toDateString(),
            'view' => 'kanban',
        ])
        ->test('pages::workspace.index')
        ->assertSet('viewMode', 'list')
        ->assertSet('filterItemType', 'projects')
        ->assertSet('focusProjectId', $project->id);

    $this->actingAs($user)->get(route('workspace', [
        'date' => now()->toDateString(),
        'project' => $project->id,
    ]))
        ->assertSuccessful()
        ->assertSee('id="workspace-item-project-'.$project->id.'"', false);
});

test('workspace clears invalid task focus id', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->withQueryParams([
            'task' => '999999',
            'date' => now()->toDateString(),
        ])
        ->test('pages::workspace.index')
        ->assertSet('focusTaskId', null);
});

test('workspace task focus persists with type filter query params', function (): void {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create([
        'title' => 'Filtered Deep Link',
        'status' => TaskStatus::ToDo,
        'end_datetime' => now()->addHours(2),
        'completed_at' => null,
    ]);

    Livewire::actingAs($user)
        ->withQueryParams([
            'date' => now()->toDateString(),
            'view' => 'list',
            'type' => 'tasks',
            'task' => (string) $task->id,
        ])
        ->test('pages::workspace.index')
        ->assertSet('focusTaskId', $task->id)
        ->assertSet('filterItemType', 'tasks');
});

test('focusCalendarAgendaItem list view clears filters without forcing item type when task is hidden by type filter', function (): void {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create([
        'title' => 'List Agenda Task',
        'status' => TaskStatus::ToDo,
        'end_datetime' => now()->addHours(2),
        'completed_at' => null,
    ]);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('viewMode', 'list')
        ->set('filterItemType', 'events')
        ->call('focusCalendarAgendaItem', 'task', $task->id)
        ->assertSet('filterItemType', null)
        ->assertSet('viewMode', 'list');
});

test('focusCalendarAgendaItem keeps kanban view when focusing a task from in-page calendar', function (): void {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create([
        'title' => 'Calendar Focus Task',
        'status' => TaskStatus::ToDo,
        'end_datetime' => now()->addHours(2),
        'completed_at' => null,
    ]);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('viewMode', 'kanban')
        ->set('filterItemType', 'events')
        ->call('focusCalendarAgendaItem', 'task', $task->id)
        ->assertSet('focusTaskId', null)
        ->assertSet('focusEventId', null)
        ->assertSet('viewMode', 'kanban')
        ->assertSet('filterItemType', 'events');
});

test('focusCalendarAgendaItem keeps kanban view when focusing an event from in-page calendar', function (): void {
    $user = User::factory()->create();
    $event = Event::factory()->for($user)->create([
        'title' => 'Calendar Focus Event',
        'status' => EventStatus::Scheduled,
        'start_datetime' => now()->addHour(),
        'end_datetime' => now()->addHours(2),
        'all_day' => false,
    ]);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('viewMode', 'kanban')
        ->set('filterItemType', 'tasks')
        ->call('focusCalendarAgendaItem', 'event', $event->id)
        ->assertSet('focusEventId', null)
        ->assertSet('focusTaskId', null)
        ->assertSet('viewMode', 'kanban')
        ->assertSet('filterItemType', null);
});

test('focusCalendarAgendaItem keeps kanban view when focusing a project from in-page calendar', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create([
        'name' => 'Calendar Focus Project',
        'start_datetime' => now()->subDay(),
        'end_datetime' => now()->addMonth(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('viewMode', 'kanban')
        ->set('filterItemType', 'tasks')
        ->call('focusCalendarAgendaItem', 'project', $project->id)
        ->assertSet('focusProjectId', null)
        ->assertSet('focusTaskId', null)
        ->assertSet('focusEventId', null)
        ->assertSet('viewMode', 'kanban')
        ->assertSet('filterItemType', null);
});

test('focusCalendarAgendaItem keeps kanban view when focusing a school class from in-page calendar', function (): void {
    $user = User::factory()->create();
    $schoolClass = SchoolClass::factory()->for($user)->create([
        'subject_name' => 'Calendar Focus School Class',
        'start_datetime' => now()->startOfDay()->addHours(9),
        'end_datetime' => now()->startOfDay()->addHours(10),
    ]);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('viewMode', 'kanban')
        ->set('filterItemType', 'tasks')
        ->call('focusCalendarAgendaItem', 'schoolClass', $schoolClass->id)
        ->assertSet('focusSchoolClassId', null)
        ->assertSet('focusTaskId', null)
        ->assertSet('viewMode', 'kanban')
        ->assertSet('filterItemType', null);
});

test('workspace bell focus event delegates to focusCalendarAgendaItem', function (): void {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create([
        'title' => 'Bell Focus Task',
        'status' => TaskStatus::ToDo,
        'end_datetime' => now()->addHours(2),
        'completed_at' => null,
    ]);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('viewMode', 'kanban')
        ->set('filterItemType', 'events')
        ->call('onWorkspaceBellFocusItem', 'task', $task->id)
        ->assertSet('focusTaskId', null)
        ->assertSet('viewMode', 'kanban')
        ->assertSet('filterItemType', 'events');
});

test('workspace bell focus event can skip pagination expansion when row already visible', function (): void {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create([
        'title' => 'Bell Instant Task',
        'status' => TaskStatus::ToDo,
        'end_datetime' => now()->addHours(2),
        'completed_at' => null,
    ]);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('viewMode', 'kanban')
        ->set('filterItemType', 'events')
        ->call('onWorkspaceBellFocusItem', 'task', $task->id, false)
        ->assertSet('focusTaskId', null)
        ->assertSet('viewMode', 'kanban')
        ->assertSet('filterItemType', 'events');
});
