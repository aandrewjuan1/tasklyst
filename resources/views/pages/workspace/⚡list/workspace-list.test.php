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
        ])
        ->assertSee($project->name)
        ->assertSee($event->title)
        ->assertSee($task->title);
});

