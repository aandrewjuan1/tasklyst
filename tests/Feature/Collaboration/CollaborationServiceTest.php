<?php

use App\Enums\CollaborationPermission;
use App\Models\Collaboration;
use App\Models\CollaborationInvitation;
use App\Models\Task;
use App\Models\User;
use App\Services\CollaborationInvitationService;
use App\Services\CollaborationService;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->invitee = User::factory()->create();
    $this->collaborationService = app(CollaborationService::class);
    $this->invitationService = app(CollaborationInvitationService::class);
});

test('create collaboration sets collaboratable user and permission', function (): void {
    $task = Task::factory()->for($this->user)->create();

    $collaboration = $this->collaborationService->createCollaboration([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->invitee->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    expect($collaboration)->toBeInstanceOf(Collaboration::class)
        ->and($collaboration->collaboratable_type)->toBe(Task::class)
        ->and($collaboration->collaboratable_id)->toBe($task->id)
        ->and($collaboration->user_id)->toBe($this->invitee->id)
        ->and($collaboration->permission)->toBe(CollaborationPermission::Edit)
        ->and($collaboration->exists)->toBeTrue();
});

test('delete collaboration removes collaboration from database', function (): void {
    $task = Task::factory()->for($this->user)->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->invitee->id,
        'permission' => CollaborationPermission::View,
    ]);
    $collaborationId = $collaboration->id;

    $result = $this->collaborationService->deleteCollaboration($collaboration);

    expect($result)->toBeTrue()
        ->and(Collaboration::find($collaborationId))->toBeNull();
});

test('create invitation sets collaboratable inviter and invitee email', function (): void {
    $task = Task::factory()->for($this->user)->create();

    $invitation = $this->invitationService->createInvitation([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $this->user->id,
        'invitee_email' => $this->invitee->email,
        'permission' => CollaborationPermission::Edit,
    ]);

    expect($invitation)->toBeInstanceOf(CollaborationInvitation::class)
        ->and($invitation->collaboratable_type)->toBe(Task::class)
        ->and($invitation->collaboratable_id)->toBe($task->id)
        ->and($invitation->inviter_id)->toBe($this->user->id)
        ->and($invitation->invitee_email)->toBe($this->invitee->email)
        ->and($invitation->permission)->toBe(CollaborationPermission::Edit)
        ->and($invitation->exists)->toBeTrue()
        ->and($invitation->token)->not->toBeEmpty()
        ->and($invitation->status)->toBe('pending');
});

test('mark accepted updates invitation status and creates collaboration', function (): void {
    $task = Task::factory()->for($this->user)->create();
    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $this->user->id,
        'invitee_email' => $this->invitee->email,
        'invitee_user_id' => null,
        'permission' => CollaborationPermission::View,
        'status' => 'pending',
    ]);
    $invitationId = $invitation->id;

    $collaboration = $this->invitationService->markAccepted($invitation, $this->invitee);

    expect($collaboration)->toBeInstanceOf(Collaboration::class)
        ->and($collaboration->user_id)->toBe($this->invitee->id)
        ->and($collaboration->collaboratable_id)->toBe($task->id)
        ->and($collaboration->permission)->toBe(CollaborationPermission::View);

    $invitation->refresh();
    expect($invitation->status)->toBe('accepted')
        ->and($invitation->invitee_user_id)->toBe($this->invitee->id);

    expect(Collaboration::query()
        ->where('collaboratable_type', Task::class)
        ->where('collaboratable_id', $task->id)
        ->where('user_id', $this->invitee->id)
        ->exists())->toBeTrue();
});
