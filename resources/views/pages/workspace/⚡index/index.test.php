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

    $component = Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->assertSet('selectedDate', $today)
        ->assertSee(Carbon::parse($today)->translatedFormat('D, M j, Y'));

    $component
        ->call('goToNextDay')
        ->assertSet('selectedDate', Carbon::parse($today)->addDay()->toDateString());

    $component
        ->call('goToPreviousDay')
        ->assertSet('selectedDate', $today);

    $component
        ->set('selectedDate', Carbon::parse($today)->subDay()->toDateString())
        ->assertSee('Today')
        ->call('goToToday')
        ->assertSet('selectedDate', $today);
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

