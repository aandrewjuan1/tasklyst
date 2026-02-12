<?php

use App\Enums\ActivityLogAction;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogRecorder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->otherUser = User::factory()->create();
    $this->recorder = app(ActivityLogRecorder::class);
});

test('load more activity logs succeeds for task with logs', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    $this->recorder->record($task, $this->owner, ActivityLogAction::ItemCreated, []);

    Livewire::test('pages::workspace.index')
        ->call('loadMoreActivityLogs', Task::class, $task->id, 0)
        ->assertOk();
});

test('load more activity logs with offset succeeds', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    for ($i = 0; $i < 8; $i++) {
        $this->recorder->record($task, $this->owner, ActivityLogAction::FieldUpdated, []);
    }

    Livewire::test('pages::workspace.index')
        ->call('loadMoreActivityLogs', Task::class, $task->id, 5)
        ->assertOk();
});

test('load more activity logs for another users task returns ok and dispatches toast', function (): void {
    $this->actingAs($this->otherUser);
    $task = Task::factory()->for($this->owner)->create();

    Livewire::test('pages::workspace.index')
        ->call('loadMoreActivityLogs', Task::class, $task->id, 0)
        ->assertOk()
        ->assertDispatched('toast');
});

test('load more activity logs unauthenticated returns ok', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $this->recorder->record($task, $this->owner, ActivityLogAction::ItemCreated, []);

    Livewire::test('pages::workspace.index')
        ->call('loadMoreActivityLogs', Task::class, $task->id, 0)
        ->assertOk();
});

test('load more activity logs for project succeeds', function (): void {
    $this->actingAs($this->owner);
    $project = Project::factory()->for($this->owner)->create();
    $this->recorder->record($project, $this->owner, ActivityLogAction::ItemCreated, ['name' => $project->name]);

    Livewire::test('pages::workspace.index')
        ->call('loadMoreActivityLogs', Project::class, $project->id, 0)
        ->assertOk();
});

test('load more activity logs for event succeeds', function (): void {
    $this->actingAs($this->owner);
    $event = Event::factory()->for($this->owner)->create();
    $this->recorder->record($event, $this->owner, ActivityLogAction::ItemCreated, []);

    Livewire::test('pages::workspace.index')
        ->call('loadMoreActivityLogs', Event::class, $event->id, 0)
        ->assertOk();
});

test('load more activity logs with invalid loggable type dispatches toast', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();

    Livewire::test('pages::workspace.index')
        ->call('loadMoreActivityLogs', 'InvalidType', $task->id, 0)
        ->assertOk()
        ->assertDispatched('toast');
});

test('collaborator with update permission can load more activity logs', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $collaborator = User::factory()->create();
    $task->collaborations()->create([
        'user_id' => $collaborator->id,
        'permission' => \App\Enums\CollaborationPermission::Edit->value,
    ]);
    $this->recorder->record($task, $this->owner, ActivityLogAction::ItemCreated, []);

    $this->actingAs($collaborator);

    Livewire::test('pages::workspace.index')
        ->call('loadMoreActivityLogs', Task::class, $task->id, 0)
        ->assertOk();
});
