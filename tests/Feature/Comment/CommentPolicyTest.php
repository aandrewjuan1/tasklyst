<?php

use App\Enums\CollaborationPermission;
use App\Models\Collaboration;
use App\Models\Comment;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->collaboratorWithEdit = User::factory()->create();
    $this->collaboratorWithView = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('view any and create allow any authenticated user', function (): void {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('viewAny', Comment::class))->toBeTrue()
        ->and(Gate::forUser($user)->allows('create', Comment::class))->toBeTrue();
});

test('owner can view comment on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->otherUser->id,
    ]);
    $comment->load('commentable');

    expect($this->owner->can('view', $comment))->toBeTrue();
});

test('collaborator with edit permission can view comment on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaboratorWithEdit->id,
        'permission' => CollaborationPermission::Edit,
    ]);
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->owner->id,
    ]);
    $comment->load('commentable');

    expect($this->collaboratorWithEdit->can('view', $comment))->toBeTrue();
});

test('collaborator with view permission can view comment on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaboratorWithView->id,
        'permission' => CollaborationPermission::View,
    ]);
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->owner->id,
    ]);
    $comment->load('commentable');

    expect($this->collaboratorWithView->can('view', $comment))->toBeTrue();
});

test('other user cannot view comment on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->owner->id,
    ]);
    $comment->load('commentable');

    expect($this->otherUser->can('view', $comment))->toBeFalse();
});

test('owner can update any comment on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->otherUser->id,
    ]);
    $comment->load('commentable');

    expect($this->owner->can('update', $comment))->toBeTrue();
});

test('comment author can update own comment on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->collaboratorWithView->id,
    ]);
    $comment->load('commentable');

    expect($this->collaboratorWithView->can('update', $comment))->toBeTrue();
});

test('collaborator with edit permission can update comment on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaboratorWithEdit->id,
        'permission' => CollaborationPermission::Edit,
    ]);
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->owner->id,
    ]);
    $comment->load('commentable');

    expect($this->collaboratorWithEdit->can('update', $comment))->toBeTrue();
});

test('collaborator with view permission cannot update comment on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaboratorWithView->id,
        'permission' => CollaborationPermission::View,
    ]);
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->owner->id,
    ]);
    $comment->load('commentable');

    expect($this->collaboratorWithView->can('update', $comment))->toBeFalse();
});

test('other user cannot update comment on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->owner->id,
    ]);
    $comment->load('commentable');

    expect($this->otherUser->can('update', $comment))->toBeFalse();
});

test('owner can delete comment on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->otherUser->id,
    ]);
    $comment->load('commentable');

    expect($this->owner->can('delete', $comment))->toBeTrue();
});

test('comment author can delete own comment on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->collaboratorWithView->id,
    ]);
    $comment->load('commentable');

    expect($this->collaboratorWithView->can('delete', $comment))->toBeTrue();
});

test('other user cannot delete comment on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->owner->id,
    ]);
    $comment->load('commentable');

    expect($this->otherUser->can('delete', $comment))->toBeFalse();
});

test('owner can restore and force delete comment on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->owner->id,
    ]);
    $comment->load('commentable');

    expect($this->owner->can('restore', $comment))->toBeTrue()
        ->and($this->owner->can('forceDelete', $comment))->toBeTrue();
});

test('comment author without update on commentable cannot restore or force delete comment', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->collaboratorWithView->id,
    ]);
    $comment->load('commentable');

    expect($this->collaboratorWithView->can('restore', $comment))->toBeFalse()
        ->and($this->collaboratorWithView->can('forceDelete', $comment))->toBeFalse();
});

test('collaborator with edit permission can restore and force delete comment on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaboratorWithEdit->id,
        'permission' => CollaborationPermission::Edit,
    ]);
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->owner->id,
    ]);
    $comment->load('commentable');

    expect($this->collaboratorWithEdit->can('restore', $comment))->toBeTrue()
        ->and($this->collaboratorWithEdit->can('forceDelete', $comment))->toBeTrue();
});

test('collaborator with view permission cannot restore or force delete comment on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaboratorWithView->id,
        'permission' => CollaborationPermission::View,
    ]);
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->owner->id,
    ]);
    $comment->load('commentable');

    expect($this->collaboratorWithView->can('restore', $comment))->toBeFalse()
        ->and($this->collaboratorWithView->can('forceDelete', $comment))->toBeFalse();
});

test('other user cannot restore or force delete comment on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->owner->id,
    ]);
    $comment->load('commentable');

    expect($this->otherUser->can('restore', $comment))->toBeFalse()
        ->and($this->otherUser->can('forceDelete', $comment))->toBeFalse();
});

test('owner can view comment on event', function (): void {
    $event = Event::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $event->id,
        'commentable_type' => Event::class,
        'user_id' => $this->owner->id,
    ]);
    $comment->load('commentable');

    expect($this->owner->can('view', $comment))->toBeTrue();
});

test('other user cannot update comment on project', function (): void {
    $project = Project::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $project->id,
        'commentable_type' => Project::class,
        'user_id' => $this->owner->id,
    ]);
    $comment->load('commentable');

    expect($this->otherUser->can('update', $comment))->toBeFalse();
});
