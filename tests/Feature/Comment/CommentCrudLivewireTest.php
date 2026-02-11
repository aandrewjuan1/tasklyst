<?php

use App\Enums\CollaborationPermission;
use App\Models\Collaboration;
use App\Models\Comment;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Validation\CommentPayloadValidation;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->collaboratorWithEdit = User::factory()->create();
    $this->collaboratorWithView = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('add comment with valid payload for task creates comment and returns id', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    $payload = [
        'commentableType' => Task::class,
        'commentableId' => $task->id,
        'content' => 'Livewire added comment',
    ];

    Livewire::test('pages::workspace.index')
        ->call('addComment', $payload);

    $comment = Comment::query()
        ->where('commentable_id', $task->id)
        ->where('commentable_type', Task::class)
        ->where('user_id', $this->owner->id)
        ->where('content', 'Livewire added comment')
        ->first();
    expect($comment)->not->toBeNull()
        ->and($comment->user_id)->toBe($this->owner->id);
});

test('add comment with valid payload for event creates comment', function (): void {
    $this->actingAs($this->owner);
    $event = Event::factory()->for($this->owner)->create();
    $payload = [
        'commentableType' => Event::class,
        'commentableId' => $event->id,
        'content' => 'Event comment',
    ];

    Livewire::test('pages::workspace.index')
        ->call('addComment', $payload);

    $comment = Comment::query()
        ->where('commentable_id', $event->id)
        ->where('commentable_type', Event::class)
        ->where('user_id', $this->owner->id)
        ->first();
    expect($comment)->not->toBeNull()
        ->and($comment->content)->toBe('Event comment');
});

test('add comment with valid payload for project creates comment', function (): void {
    $this->actingAs($this->owner);
    $project = Project::factory()->for($this->owner)->create();
    $payload = [
        'commentableType' => Project::class,
        'commentableId' => $project->id,
        'content' => 'Project comment',
    ];

    Livewire::test('pages::workspace.index')
        ->call('addComment', $payload);

    $comment = Comment::query()
        ->where('commentable_id', $project->id)
        ->where('commentable_type', Project::class)
        ->where('user_id', $this->owner->id)
        ->first();
    expect($comment)->not->toBeNull()
        ->and($comment->content)->toBe('Project comment');
});

test('add comment with empty content does not create comment', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    $countBefore = Comment::query()->count();
    $payload = array_replace_recursive(CommentPayloadValidation::createDefaults(), [
        'commentableType' => Task::class,
        'commentableId' => $task->id,
        'content' => '',
    ]);

    Livewire::test('pages::workspace.index')
        ->call('addComment', $payload);

    expect(Comment::query()->count())->toBe($countBefore);
});

test('add comment with whitespace only content does not create comment', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    $countBefore = Comment::query()->count();
    $payload = [
        'commentableType' => Task::class,
        'commentableId' => $task->id,
        'content' => '   ',
    ];

    Livewire::test('pages::workspace.index')
        ->call('addComment', $payload);

    expect(Comment::query()->count())->toBe($countBefore);
});

test('add comment on other user task does not create comment', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $this->actingAs($this->otherUser);
    $countBefore = Comment::query()->count();
    $payload = [
        'commentableType' => Task::class,
        'commentableId' => $task->id,
        'content' => 'Should not persist',
    ];

    Livewire::test('pages::workspace.index')
        ->call('addComment', $payload);

    expect(Comment::query()->count())->toBe($countBefore);
    $comment = Comment::query()->where('content', 'Should not persist')->first();
    expect($comment)->toBeNull();
});

test('collaborator with edit permission can add comment on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaboratorWithEdit->id,
        'permission' => CollaborationPermission::Edit,
    ]);
    $this->actingAs($this->collaboratorWithEdit);
    $payload = [
        'commentableType' => Task::class,
        'commentableId' => $task->id,
        'content' => 'Collaborator comment',
    ];

    Livewire::test('pages::workspace.index')
        ->call('addComment', $payload);

    $comment = Comment::query()
        ->where('commentable_id', $task->id)
        ->where('user_id', $this->collaboratorWithEdit->id)
        ->where('content', 'Collaborator comment')
        ->first();
    expect($comment)->not->toBeNull();
});

test('collaborator with view permission cannot add comment on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaboratorWithView->id,
        'permission' => CollaborationPermission::View,
    ]);
    $this->actingAs($this->collaboratorWithView);
    $countBefore = Comment::query()->count();
    $payload = [
        'commentableType' => Task::class,
        'commentableId' => $task->id,
        'content' => 'View only cannot add',
    ];

    Livewire::test('pages::workspace.index')
        ->call('addComment', $payload)
        ->assertForbidden();

    expect(Comment::query()->count())->toBe($countBefore);
});

test('unauthenticated add comment does not create comment', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $countBefore = Comment::query()->count();
    $payload = [
        'commentableType' => Task::class,
        'commentableId' => $task->id,
        'content' => 'Guest comment',
    ];

    Livewire::test('pages::workspace.index')
        ->call('addComment', $payload);

    expect(Comment::query()->count())->toBe($countBefore);
});

test('owner can update comment and comment is updated in database', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->owner->id,
        'content' => 'Original content',
    ]);

    Livewire::test('pages::workspace.index')
        ->call('updateComment', $comment->id, [
            'content' => 'Updated content',
            'isPinned' => true,
        ]);

    expect($comment->fresh()->content)->toBe('Updated content')
        ->and($comment->fresh()->is_pinned)->toBeTrue();
});

test('comment author can update own comment', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->collaboratorWithEdit->id,
        'content' => 'Own comment',
    ]);
    Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaboratorWithEdit->id,
        'permission' => CollaborationPermission::Edit,
    ]);
    $this->actingAs($this->collaboratorWithEdit);

    Livewire::test('pages::workspace.index')
        ->call('updateComment', $comment->id, [
            'content' => 'Edited by author',
            'isPinned' => false,
        ]);

    expect($comment->fresh()->content)->toBe('Edited by author');
});

test('update comment with empty content does not update comment', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->owner->id,
        'content' => 'Original',
    ]);

    Livewire::test('pages::workspace.index')
        ->call('updateComment', $comment->id, array_replace_recursive(CommentPayloadValidation::updateDefaults(), ['content' => '']));

    expect($comment->fresh()->content)->toBe('Original');
});

test('other user cannot update comment on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->owner->id,
        'content' => 'Owner comment',
    ]);
    $this->actingAs($this->otherUser);

    Livewire::test('pages::workspace.index')
        ->call('updateComment', $comment->id, [
            'content' => 'Hacked content',
            'isPinned' => false,
        ])
        ->assertForbidden();

    expect($comment->fresh()->content)->toBe('Owner comment');
});

test('update comment with non existent id returns without updating', function (): void {
    $this->actingAs($this->owner);
    $countBefore = Comment::query()->count();

    Livewire::test('pages::workspace.index')
        ->call('updateComment', 99999, [
            'content' => 'Content',
            'isPinned' => false,
        ]);

    expect(Comment::query()->count())->toBe($countBefore);
});

test('owner can delete comment and comment is removed', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->owner->id,
    ]);
    $commentId = $comment->id;

    Livewire::test('pages::workspace.index')
        ->call('deleteComment', $commentId);

    expect(Comment::find($commentId))->toBeNull();
});

test('comment author can delete own comment', function (): void {
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
        'user_id' => $this->collaboratorWithEdit->id,
    ]);
    $commentId = $comment->id;
    $this->actingAs($this->collaboratorWithEdit);

    Livewire::test('pages::workspace.index')
        ->call('deleteComment', $commentId);

    expect(Comment::find($commentId))->toBeNull();
});

test('other user cannot delete comment on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->owner->id,
    ]);
    $this->actingAs($this->otherUser);

    Livewire::test('pages::workspace.index')
        ->call('deleteComment', $comment->id)
        ->assertForbidden();

    expect(Comment::find($comment->id))->not->toBeNull();
});

test('delete comment with non existent id does not throw', function (): void {
    $this->actingAs($this->owner);
    $countBefore = Comment::query()->count();

    Livewire::test('pages::workspace.index')
        ->call('deleteComment', 99999);

    expect(Comment::query()->count())->toBe($countBefore);
});

test('unauthenticated update comment returns without updating', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->owner->id,
        'content' => 'Original',
    ]);

    Livewire::test('pages::workspace.index')
        ->call('updateComment', $comment->id, [
            'content' => 'Should not update',
            'isPinned' => false,
        ]);

    expect($comment->fresh()->content)->toBe('Original');
});

test('unauthenticated delete comment does not delete', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $comment = Comment::factory()->create([
        'commentable_id' => $task->id,
        'commentable_type' => Task::class,
        'user_id' => $this->owner->id,
    ]);

    Livewire::test('pages::workspace.index')
        ->call('deleteComment', $comment->id);

    expect(Comment::find($comment->id))->not->toBeNull();
});
