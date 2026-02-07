<?php

use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Livewire;

it('renders the workspace list component', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::workspace.list', [
            'projects' => collect(),
            'events' => collect(),
            'tasks' => collect(),
            'overdue' => collect(),
            'tags' => collect(),
        ])
        ->assertStatus(200);
});

it('displays provided projects events and tasks', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()->for($user)->create();
    $event = Event::factory()->for($user)->create();
    $task = Task::factory()->for($user)->create();

    $projects = Collection::make([$project]);
    $events = Collection::make([$event]);
    $tasks = Collection::make([$task]);

    Livewire::actingAs($user)
        ->test('pages::workspace.list', [
            'projects' => $projects,
            'events' => $events,
            'tasks' => $tasks,
            'overdue' => collect(),
            'tags' => collect(),
        ])
        ->assertSee($project->name)
        ->assertSee($event->title)
        ->assertSee($task->title);
});

it('styles item properties as visible pill badges', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()->for($user)->create();
    $event = Event::factory()->for($user)->create();
    $task = Task::factory()->for($user)->for($project)->create();

    Livewire::actingAs($user)
        ->test('pages::workspace.list', [
            'projects' => Collection::make([$project]),
            'events' => Collection::make([$event]),
            'tasks' => Collection::make([$task]),
            'overdue' => collect(),
            'tags' => collect(),
        ])
        ->assertSee($project->name)
        ->assertSee($event->title)
        ->assertSee($task->title)
        ->assertSee('rounded-full border', escape: false)
        ->assertSee('px-2.5 py-0.5', escape: false);
});

it('displays empty state when all collections are empty', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::workspace.list', [
            'projects' => collect(),
            'events' => collect(),
            'tasks' => collect(),
            'overdue' => collect(),
            'tags' => collect(),
        ])
        ->assertSee(__('No tasks, projects, or events for :date', ['date' => __('today')]))
        ->assertSee(__('Add a task, project, or event for this day to get started'));
});

it('displays add new item dropdown button', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::workspace.list', [
            'projects' => collect(),
            'events' => collect(),
            'tasks' => collect(),
            'overdue' => collect(),
            'tags' => collect(),
        ])
        ->assertSee('Add');
});

it('includes project option in add dropdown with task and event', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::workspace.list', [
            'projects' => collect(),
            'events' => collect(),
            'tasks' => collect(),
            'overdue' => collect(),
            'tags' => collect(),
        ])
        ->assertSee(__('Task'))
        ->assertSee(__('Event'))
        ->assertSee(__('Project'));
});

it('includes inline task date range validation message', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::workspace.list', [
            'projects' => collect(),
            'events' => collect(),
            'tasks' => collect(),
            'overdue' => collect(),
            'tags' => collect(),
        ])
        ->assertSee(__('End date must be the same as or after the start date.'));
});

it('includes inline task duration vs end time validation message', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::workspace.list', [
            'projects' => collect(),
            'events' => collect(),
            'tasks' => collect(),
            'overdue' => collect(),
            'tags' => collect(),
        ])
        ->assertSee(__('End time must be at least :minutes minutes after the start time.', ['minutes' => ':minutes']));
});

it('displays only projects when events and tasks are empty', function (): void {
    $user = User::factory()->create();

    $project1 = Project::factory()->for($user)->create(['name' => 'First Project']);
    $project2 = Project::factory()->for($user)->create(['name' => 'Second Project']);

    Livewire::actingAs($user)
        ->test('pages::workspace.list', [
            'projects' => Collection::make([$project1, $project2]),
            'events' => collect(),
            'tasks' => collect(),
            'overdue' => collect(),
            'tags' => collect(),
        ])
        ->assertSee('First Project')
        ->assertSee('Second Project')
        ->assertDontSee(__('No tasks, projects, or events for :date', ['date' => __('today')]));
});

it('displays only events when projects and tasks are empty', function (): void {
    $user = User::factory()->create();

    $event1 = Event::factory()->for($user)->create(['title' => 'First Event']);
    $event2 = Event::factory()->for($user)->create(['title' => 'Second Event']);

    Livewire::actingAs($user)
        ->test('pages::workspace.list', [
            'projects' => collect(),
            'events' => Collection::make([$event1, $event2]),
            'tasks' => collect(),
            'overdue' => collect(),
            'tags' => collect(),
        ])
        ->assertSee('First Event')
        ->assertSee('Second Event')
        ->assertDontSee(__('No tasks, projects, or events for :date', ['date' => __('today')]));
});

it('displays only tasks when projects and events are empty', function (): void {
    $user = User::factory()->create();

    $task1 = Task::factory()->for($user)->create(['title' => 'First Task']);
    $task2 = Task::factory()->for($user)->create(['title' => 'Second Task']);

    Livewire::actingAs($user)
        ->test('pages::workspace.list', [
            'projects' => collect(),
            'events' => collect(),
            'tasks' => Collection::make([$task1, $task2]),
            'overdue' => collect(),
            'tags' => collect(),
        ])
        ->assertSee('First Task')
        ->assertSee('Second Task')
        ->assertDontSee(__('No tasks, projects, or events for :date', ['date' => __('today')]));
});

it('displays multiple items in each category', function (): void {
    $user = User::factory()->create();

    $projects = Project::factory()->for($user)->count(3)->create();
    $events = Event::factory()->for($user)->count(2)->create();
    $tasks = Task::factory()->for($user)->count(4)->create();

    $component = Livewire::actingAs($user)
        ->test('pages::workspace.list', [
            'projects' => $projects,
            'events' => $events,
            'tasks' => $tasks,
            'overdue' => collect(),
            'tags' => collect(),
        ]);

    foreach ($projects as $project) {
        $component->assertSee($project->name);
    }

    foreach ($events as $event) {
        $component->assertSee($event->title);
    }

    foreach ($tasks as $task) {
        $component->assertSee($task->title);
    }
});

it('orders all items by created date regardless of type', function (): void {
    $user = User::factory()->create();

    $oldProject = Project::factory()->for($user)->create([
        'name' => 'Old Project',
        'created_at' => now()->subDays(2),
    ]);

    $middleEvent = Event::factory()->for($user)->create([
        'title' => 'Middle Event',
        'created_at' => now()->subDay(),
    ]);

    $newTask = Task::factory()->for($user)->create([
        'title' => 'Newest Task',
        'created_at' => now(),
    ]);

    $projects = Collection::make([$oldProject]);
    $events = Collection::make([$middleEvent]);
    $tasks = Collection::make([$newTask]);

    Livewire::actingAs($user)
        ->test('pages::workspace.list', [
            'projects' => $projects,
            'events' => $events,
            'tasks' => $tasks,
            'overdue' => collect(),
            'tags' => collect(),
        ])
        ->assertSeeInOrder([
            'Newest Task',
            'Middle Event',
            'Old Project',
        ]);
});

it('does not display empty state when at least one collection has items', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('pages::workspace.list', [
            'projects' => Collection::make([$project]),
            'events' => collect(),
            'tasks' => collect(),
            'overdue' => collect(),
            'tags' => collect(),
        ])
        ->assertDontSee(__('No tasks, projects, or events for :date', ['date' => __('today')]))
        ->assertDontSee(__('Add a task, project, or event for this day to get started'));
});

it('renders loading card structure for task creation', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::workspace.list', [
            'projects' => collect(),
            'events' => collect(),
            'tasks' => collect(),
            'overdue' => collect(),
            'tags' => collect(),
        ])
        ->assertSee('data-test="task-loading-card"', escape: false);
});

it('renders delete actions for project event and task cards', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()->for($user)->create();
    $event = Event::factory()->for($user)->create();
    $task = Task::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('pages::workspace.list', [
            'projects' => Collection::make([$project]),
            'events' => Collection::make([$event]),
            'tasks' => Collection::make([$task]),
            'overdue' => collect(),
            'tags' => collect(),
        ])
        ->assertSee('deleteProject')
        ->assertSee('deleteEvent')
        ->assertSee('deleteTask');
});

it('displays clickable property dropdowns for task with status priority complexity and duration', function (): void {
    $user = User::factory()->create();

    $task = Task::factory()->for($user)->create([
        'status' => \App\Enums\TaskStatus::Doing,
        'priority' => \App\Enums\TaskPriority::High,
        'complexity' => \App\Enums\TaskComplexity::Moderate,
        'duration' => 60,
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::workspace.list', [
            'projects' => collect(),
            'events' => collect(),
            'tasks' => Collection::make([$task]),
            'overdue' => collect(),
            'tags' => collect(),
        ])
        ->assertSee($task->title);

    $component->assertSee(__('Status'));
    $component->assertSee(__('Priority'));
    $component->assertSee(__('Complexity'));
    $component->assertSee(__('Duration'));
    $component->assertSee('aria-haspopup="menu"', escape: false);
});

it('does not show overdue badge when there are no overdue items', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::workspace.list', [
            'projects' => collect(),
            'events' => collect(),
            'tasks' => collect(),
            'overdue' => collect(),
            'tags' => collect(),
        ])
        ->assertDontSee(__('Overdue'));
});

it('shows overdue items in main list with overdue badge', function (): void {
    $user = User::factory()->create();
    $overdueTask = Task::factory()->for($user)->create([
        'title' => 'Overdue Task',
        'end_datetime' => now()->subDay(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::workspace.list', [
            'projects' => collect(),
            'events' => collect(),
            'tasks' => collect(),
            'overdue' => Collection::make([['kind' => 'task', 'item' => $overdueTask]]),
            'tags' => collect(),
        ])
        ->assertSee(__('Overdue'))
        ->assertSee('Overdue Task');
});

it('hides empty state when there are overdue items but no current items', function (): void {
    $user = User::factory()->create();
    $overdueTask = Task::factory()->for($user)->create([
        'title' => 'Overdue Task',
        'end_datetime' => now()->subDay(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::workspace.list', [
            'projects' => collect(),
            'events' => collect(),
            'tasks' => collect(),
            'overdue' => Collection::make([['kind' => 'task', 'item' => $overdueTask]]),
            'tags' => collect(),
        ])
        ->assertDontSee(__('No tasks, projects, or events for :date', ['date' => __('today')]))
        ->assertDontSee(__('Add a task, project, or event for this day to get started'));
});
