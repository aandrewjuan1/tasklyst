<?php

use App\Actions\Collaboration\AcceptCollaborationInvitationAction;
use App\Actions\Collaboration\CreateCollaborationInvitationAction;
use App\Actions\Collaboration\DeclineCollaborationInvitationAction;
use App\Actions\Collaboration\DeleteCollaborationAction;
use App\Actions\Collaboration\UpdateCollaborationPermissionAction;
use App\Actions\Comment\CreateCommentAction;
use App\Actions\Comment\DeleteCommentAction;
use App\Actions\Comment\UpdateCommentAction;
use App\DataTransferObjects\Collaboration\CreateCollaborationInvitationDto;
use App\DataTransferObjects\Comment\CreateCommentDto;
use App\DataTransferObjects\Comment\UpdateCommentDto;
use App\Enums\CollaborationPermission;
use App\Models\Collaboration;
use App\Models\CollaborationInvitation;
use App\Models\Comment;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->task = Task::factory()->for($this->user)->create();
});

it('creates a comment on a task', function (): void {
    $dto = new CreateCommentDto(
        commentableType: Task::class,
        commentableId: $this->task->id,
        content: 'Test comment content',
    );

    $comment = app(CreateCommentAction::class)->execute($this->user, $dto);

    expect($comment)->toBeInstanceOf(Comment::class)
        ->and($comment->content)->toBe('Test comment content')
        ->and($comment->commentable_id)->toBe($this->task->id)
        ->and($comment->commentable_type)->toBe(Task::class)
        ->and($comment->user_id)->toBe($this->user->id)
        ->and($comment->commentable)->toBeInstanceOf(Task::class)
        ->and($this->task->comments)->toHaveCount(1);
});

it('creates a comment on an event', function (): void {
    $event = Event::factory()->for($this->user)->create();

    $dto = new CreateCommentDto(
        commentableType: Event::class,
        commentableId: $event->id,
        content: 'Event comment',
    );

    $comment = app(CreateCommentAction::class)->execute($this->user, $dto);

    expect($comment)->toBeInstanceOf(Comment::class)
        ->and($comment->commentable_id)->toBe($event->id)
        ->and($comment->commentable_type)->toBe(Event::class)
        ->and($comment->commentable)->toBeInstanceOf(Event::class)
        ->and($event->comments)->toHaveCount(1);
});

it('creates a comment on a project', function (): void {
    $project = Project::factory()->for($this->user)->create();

    $dto = new CreateCommentDto(
        commentableType: Project::class,
        commentableId: $project->id,
        content: 'Project comment',
    );

    $comment = app(CreateCommentAction::class)->execute($this->user, $dto);

    expect($comment)->toBeInstanceOf(Comment::class)
        ->and($comment->commentable_id)->toBe($project->id)
        ->and($comment->commentable_type)->toBe(Project::class)
        ->and($comment->commentable)->toBeInstanceOf(Project::class)
        ->and($project->comments)->toHaveCount(1);
});

it('updates a comment', function (): void {
    $comment = Comment::factory()->for($this->task, 'commentable')->for($this->user)->create([
        'content' => 'Original content',
    ]);

    $dto = new UpdateCommentDto(
        content: 'Updated content',
        isPinned: true,
    );

    $updated = app(UpdateCommentAction::class)->execute($comment, $dto);

    expect($updated->content)->toBe('Updated content')
        ->and($updated->is_pinned)->toBeTrue()
        ->and($updated->is_edited)->toBeTrue();
});

it('does not set is_edited when only pin is toggled', function (): void {
    $comment = Comment::factory()->for($this->task, 'commentable')->for($this->user)->create([
        'content' => 'Same content',
        'is_edited' => false,
    ]);

    $dto = new UpdateCommentDto(
        content: 'Same content',
        isPinned: true,
    );

    $updated = app(UpdateCommentAction::class)->execute($comment, $dto);

    expect($updated->is_pinned)->toBeTrue()
        ->and($updated->is_edited)->toBeFalse()
        ->and($updated->edited_at)->toBeNull();
});

it('deletes a comment', function (): void {
    $comment = Comment::factory()->for($this->task, 'commentable')->for($this->user)->create();

    $deleted = app(DeleteCommentAction::class)->execute($comment);

    expect($deleted)->toBeTrue()
        ->and(Comment::find($comment->id))->toBeNull();
});

it('creates a collaboration invitation on a task', function (): void {
    $invitee = User::factory()->create();

    $dto = new CreateCollaborationInvitationDto(
        collaboratableType: 'task',
        collaboratableId: $this->task->id,
        inviterId: $this->user->id,
        inviteeEmail: $invitee->email,
        permission: CollaborationPermission::Edit,
    );

    $invitation = app(CreateCollaborationInvitationAction::class)->execute($dto);

    expect($invitation)->toBeInstanceOf(CollaborationInvitation::class)
        ->and($invitation->collaboratable_id)->toBe($this->task->id)
        ->and($invitation->collaboratable_type)->toBe(Task::class)
        ->and($invitation->inviter_id)->toBe($this->user->id)
        ->and($invitation->invitee_email)->toBe($invitee->email)
        ->and($invitation->status)->toBe('pending');
});

it('deletes a collaboration', function (): void {
    $invitee = User::factory()->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $this->task->id,
        'user_id' => $invitee->id,
        'permission' => CollaborationPermission::View,
    ]);

    $deleted = app(DeleteCollaborationAction::class)->execute($collaboration);

    expect($deleted)->toBeTrue()
        ->and(Collaboration::find($collaboration->id))->toBeNull();
});

it('accepts a collaboration invitation for the correct user', function (): void {
    $invitee = User::factory()->create();

    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $this->task->id,
        'inviter_id' => $this->user->id,
        'invitee_email' => $invitee->email,
        'permission' => CollaborationPermission::Edit,
        'status' => 'pending',
    ]);

    $collaboration = app(AcceptCollaborationInvitationAction::class)->execute($invitation, $invitee);

    expect($invitation->refresh()->status)->toBe('accepted')
        ->and($invitation->invitee_user_id)->toBe($invitee->id)
        ->and($collaboration)->toBeInstanceOf(Collaboration::class)
        ->and($collaboration->user_id)->toBe($invitee->id)
        ->and($collaboration->collaboratable_id)->toBe($this->task->id);
});

it('declines a collaboration invitation for the correct user', function (): void {
    $invitee = User::factory()->create();

    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $this->task->id,
        'inviter_id' => $this->user->id,
        'invitee_email' => $invitee->email,
        'permission' => CollaborationPermission::View,
        'status' => 'pending',
    ]);

    $ok = app(DeclineCollaborationInvitationAction::class)->execute($invitation, $invitee);

    expect($ok)->toBeTrue()
        ->and($invitation->refresh()->status)->toBe('declined')
        ->and($invitation->invitee_user_id)->toBe($invitee->id);
});

it('updates a collaboration permission', function (): void {
    $invitee = User::factory()->create();

    $collaboration = Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $this->task->id,
        'user_id' => $invitee->id,
        'permission' => CollaborationPermission::View,
    ]);

    $updated = app(UpdateCollaborationPermissionAction::class)->execute($collaboration, CollaborationPermission::Edit);

    expect($updated->permission)->toBe(CollaborationPermission::Edit)
        ->and($collaboration->fresh()->permission)->toBe(CollaborationPermission::Edit);
});
