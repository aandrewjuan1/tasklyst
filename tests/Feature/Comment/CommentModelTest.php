<?php

use App\Models\Comment;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('create comment via factory sets commentable user and content attributes', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->owner->id,
        'content' => 'Test comment content',
        'is_edited' => false,
        'is_pinned' => false,
    ]);

    expect($comment->commentable_id)->toBe($task->id)
        ->and($comment->commentable_type)->toBe(Task::class)
        ->and($comment->user_id)->toBe($this->owner->id)
        ->and($comment->content)->toBe('Test comment content')
        ->and($comment->is_edited)->toBeFalse()
        ->and($comment->is_pinned)->toBeFalse()
        ->and($comment->exists)->toBeTrue();
});

test('commentable returns correct task instance', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->owner->id,
    ]);

    expect($comment->commentable)->toBeInstanceOf(Task::class)
        ->and($comment->commentable->id)->toBe($task->id);
});

test('commentable returns correct event instance', function (): void {
    $event = Event::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $event->id,
        'commentable_type' => Event::class,
        'user_id' => $this->owner->id,
    ]);

    expect($comment->commentable)->toBeInstanceOf(Event::class)
        ->and($comment->commentable->id)->toBe($event->id);
});

test('commentable returns correct project instance', function (): void {
    $project = Project::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $project->id,
        'commentable_type' => Project::class,
        'user_id' => $this->owner->id,
    ]);

    expect($comment->commentable)->toBeInstanceOf(Project::class)
        ->and($comment->commentable->id)->toBe($project->id);
});

test('user returns comment author', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->owner->id,
    ]);

    expect($comment->user)->not->toBeNull()
        ->and($comment->user->id)->toBe($this->owner->id);
});

test('task comments relationship returns attached comments ordered by pinned then created_at', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $pinned = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->owner->id,
        'is_pinned' => true,
        'content' => 'Pinned first',
    ]);
    $normal = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->owner->id,
        'is_pinned' => false,
        'content' => 'Normal second',
    ]);

    $task->load('comments');
    expect($task->comments)->toHaveCount(2)
        ->and($task->comments->first()->id)->toBe($pinned->id)
        ->and($task->comments->first()->is_pinned)->toBeTrue()
        ->and($task->comments->last()->id)->toBe($normal->id);
});

test('event comments relationship returns attached comments', function (): void {
    $event = Event::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $event->id,
        'commentable_type' => Event::class,
        'user_id' => $this->owner->id,
    ]);

    $event->load('comments');
    expect($event->comments)->toHaveCount(1)
        ->and($event->comments->first()->id)->toBe($comment->id);
});

test('project comments relationship returns attached comments', function (): void {
    $project = Project::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $project->id,
        'commentable_type' => Project::class,
        'user_id' => $this->owner->id,
    ]);

    $project->load('comments');
    expect($project->comments)->toHaveCount(1)
        ->and($project->comments->first()->id)->toBe($comment->id);
});
