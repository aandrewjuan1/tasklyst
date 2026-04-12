<?php

use App\Enums\EventStatus;
use App\Enums\TaskStatus;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Livewire::flushState();
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

    $this->actingAs($user)->get(route('workspace', [
        'date' => now()->toDateString(),
        'event' => $event->id,
    ]))
        ->assertSuccessful()
        ->assertSee('id="workspace-item-event-'.$event->id.'"', false);
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

test('focusCalendarAgendaItem switches to list tasks with task focus from in-page calendar', function (): void {
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
        ->assertSet('focusTaskId', $task->id)
        ->assertSet('focusEventId', null)
        ->assertSet('viewMode', 'list')
        ->assertSet('filterItemType', 'tasks');
});

test('focusCalendarAgendaItem switches to list events with event focus from in-page calendar', function (): void {
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
        ->assertSet('focusEventId', $event->id)
        ->assertSet('focusTaskId', null)
        ->assertSet('viewMode', 'list')
        ->assertSet('filterItemType', 'events');
});
