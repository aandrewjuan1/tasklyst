<?php

use App\Enums\CollaborationPermission;
use App\Models\Comment;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('load more comments succeeds for task with comments', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    Comment::factory()->for($this->owner)->for($task, 'commentable')->count(3)->create();

    Livewire::test('pages::workspace.index')
        ->call('loadMoreComments', Task::class, $task->id, 0)
        ->assertOk();
});

test('load more comments with offset succeeds', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    Comment::factory()->for($this->owner)->for($task, 'commentable')->count(15)->create();

    Livewire::test('pages::workspace.index')
        ->call('loadMoreComments', Task::class, $task->id, 5)
        ->assertOk();
});

test('load more comments for another users task returns ok and dispatches toast', function (): void {
    $this->actingAs($this->otherUser);
    $task = Task::factory()->for($this->owner)->create();

    Livewire::test('pages::workspace.index')
        ->call('loadMoreComments', Task::class, $task->id, 0)
        ->assertOk()
        ->assertDispatched('toast');
});

test('load more comments unauthenticated returns ok', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    Comment::factory()->for($this->owner)->for($task, 'commentable')->create();

    Livewire::test('pages::workspace.index')
        ->call('loadMoreComments', Task::class, $task->id, 0)
        ->assertOk();
});

test('load more comments for project succeeds', function (): void {
    $this->actingAs($this->owner);
    $project = Project::factory()->for($this->owner)->create();
    Comment::factory()->for($this->owner)->for($project, 'commentable')->count(2)->create();

    Livewire::test('pages::workspace.index')
        ->call('loadMoreComments', Project::class, $project->id, 0)
        ->assertOk();
});

test('load more comments for event succeeds', function (): void {
    $this->actingAs($this->owner);
    $event = Event::factory()->for($this->owner)->create();
    Comment::factory()->for($this->owner)->for($event, 'commentable')->count(2)->create();

    Livewire::test('pages::workspace.index')
        ->call('loadMoreComments', Event::class, $event->id, 0)
        ->assertOk();
});

test('load more comments with invalid commentable type dispatches toast', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();

    Livewire::test('pages::workspace.index')
        ->call('loadMoreComments', 'InvalidType', $task->id, 0)
        ->assertOk()
        ->assertDispatched('toast');
});

test('collaborator with update permission can load more comments', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $collaborator = User::factory()->create();
    $task->collaborations()->create([
        'user_id' => $collaborator->id,
        'permission' => CollaborationPermission::Edit->value,
    ]);
    Comment::factory()->for($this->owner)->for($task, 'commentable')->create();

    $this->actingAs($collaborator);

    Livewire::test('pages::workspace.index')
        ->call('loadMoreComments', Task::class, $task->id, 0)
        ->assertOk();
});
