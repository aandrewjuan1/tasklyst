<?php

use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use App\Services\CommentService;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->service = app(CommentService::class);
});

test('create comment sets commentable and user and content', function (): void {
    $task = Task::factory()->for($this->user)->create();

    $comment = $this->service->createComment($this->user, $task, ['content' => 'First comment']);

    expect($comment)->toBeInstanceOf(Comment::class)
        ->and($comment->commentable_id)->toBe($task->id)
        ->and($comment->commentable_type)->toBe(Task::class)
        ->and($comment->user_id)->toBe($this->user->id)
        ->and($comment->content)->toBe('First comment')
        ->and($comment->exists)->toBeTrue();
});

test('create comment does not allow client to override commentable or user', function (): void {
    $task = Task::factory()->for($this->user)->create();
    $otherUser = User::factory()->create();
    $otherTask = Task::factory()->for($otherUser)->create();

    $comment = $this->service->createComment($this->user, $task, [
        'content' => 'Content',
        'commentable_id' => $otherTask->id,
        'commentable_type' => get_class($otherTask),
        'user_id' => $otherUser->id,
    ]);

    expect($comment->commentable_id)->toBe($task->id)
        ->and($comment->commentable_type)->toBe(Task::class)
        ->and($comment->user_id)->toBe($this->user->id);
});

test('update comment updates content and is_pinned', function (): void {
    $task = Task::factory()->for($this->user)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->user->id,
        'content' => 'Original',
        'is_pinned' => false,
    ]);

    $updated = $this->service->updateComment($comment, [
        'content' => 'Updated content',
        'is_pinned' => true,
    ]);

    expect($updated->content)->toBe('Updated content')
        ->and($comment->fresh()->content)->toBe('Updated content')
        ->and($comment->fresh()->is_pinned)->toBeTrue();
});

test('update comment sets is_edited and edited_at when content changes', function (): void {
    $task = Task::factory()->for($this->user)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->user->id,
        'content' => 'Original',
        'is_edited' => false,
        'edited_at' => null,
    ]);

    $this->service->updateComment($comment, ['content' => 'Changed content']);

    $comment->refresh();
    expect($comment->is_edited)->toBeTrue()
        ->and($comment->edited_at)->not->toBeNull();
});

test('update comment does not set is_edited when content unchanged', function (): void {
    $task = Task::factory()->for($this->user)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->user->id,
        'content' => 'Same content',
        'is_edited' => false,
        'edited_at' => null,
    ]);

    $this->service->updateComment($comment, ['content' => 'Same content']);

    $comment->refresh();
    expect($comment->is_edited)->toBeFalse()
        ->and($comment->edited_at)->toBeNull();
});

test('update comment strips commentable and user_id from attributes', function (): void {
    $task = Task::factory()->for($this->user)->create();
    $otherUser = User::factory()->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->user->id,
        'content' => 'Original',
    ]);

    $this->service->updateComment($comment, [
        'content' => 'Updated',
        'commentable_id' => 999,
        'commentable_type' => 'Fake',
        'user_id' => $otherUser->id,
    ]);

    $comment->refresh();
    expect($comment->content)->toBe('Updated')
        ->and($comment->commentable_id)->toBe($task->id)
        ->and($comment->user_id)->toBe($this->user->id);
});

test('delete comment removes comment from database', function (): void {
    $task = Task::factory()->for($this->user)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->user->id,
    ]);
    $commentId = $comment->id;

    $result = $this->service->deleteComment($comment);

    expect($result)->toBeTrue()
        ->and(Comment::find($commentId))->toBeNull();
});
