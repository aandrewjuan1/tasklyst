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
        ->assertSee(Carbon::parse($today)->translatedFormat('D, M j, Y'))
        ->assertDontSee('Today');
});

it('shows tasks, projects, and events for the selected date', function (): void {
    $user = User::factory()->create();

    $date = Carbon::create(2026, 1, 27);

    $project = Project::factory()->for($user)->create([
        'start_datetime' => $date->copy()->startOfDay(),
    ]);

    Task::factory()->for($user)->for($project)->create([
        'start_datetime' => $date->copy()->startOfDay(),
        'completed_at' => null,
    ]);

    Event::factory()->for($user)->create([
        'start_datetime' => $date->copy()->startOfDay(),
    ]);

    $formattedDate = $date->translatedFormat('D, M j, Y');

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->set('selectedDate', $date->toDateString())
        ->assertSee($formattedDate)
        ->assertSee($project->name)
        ->assertSee($project->tasks->first()->title)
        ->assertSee(Event::first()->title);
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

it('dispatches success toast when task is created successfully', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('createTask', [
            'title' => 'Test Task',
            'status' => 'to_do',
            'priority' => 'medium',
            'complexity' => 'moderate',
            'duration' => 60,
            'startDatetime' => null,
            'endDatetime' => null,
            'projectId' => null,
        ])
        ->assertDispatched('toast', type: 'success', message: __('Task created.'));
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
