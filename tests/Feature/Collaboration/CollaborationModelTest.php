<?php

use App\Enums\CollaborationPermission;
use App\Models\Collaboration;
use App\Models\CollaborationInvitation;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->collaborator = User::factory()->create();
});

test('collaboration create sets collaboratable user and permission', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    expect($collaboration->collaboratable_type)->toBe(Task::class)
        ->and($collaboration->collaboratable_id)->toBe($task->id)
        ->and($collaboration->user_id)->toBe($this->collaborator->id)
        ->and($collaboration->permission)->toBe(CollaborationPermission::Edit)
        ->and($collaboration->exists)->toBeTrue();
});

test('collaboration collaboratable returns correct task instance', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::View,
    ]);

    expect($collaboration->collaboratable)->toBeInstanceOf(Task::class)
        ->and($collaboration->collaboratable->id)->toBe($task->id);
});

test('collaboration collaboratable returns correct event instance', function (): void {
    $event = Event::factory()->for($this->owner)->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Event::class,
        'collaboratable_id' => $event->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    expect($collaboration->collaboratable)->toBeInstanceOf(Event::class)
        ->and($collaboration->collaboratable->id)->toBe($event->id);
});

test('collaboration collaboratable returns correct project instance', function (): void {
    $project = Project::factory()->for($this->owner)->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Project::class,
        'collaboratable_id' => $project->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::View,
    ]);

    expect($collaboration->collaboratable)->toBeInstanceOf(Project::class)
        ->and($collaboration->collaboratable->id)->toBe($project->id);
});

test('collaboration user returns collaborator user', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    expect($collaboration->user)->not->toBeNull()
        ->and($collaboration->user->id)->toBe($this->collaborator->id);
});

test('collaboration permission is cast to enum', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    expect($collaboration->permission)->toBe(CollaborationPermission::Edit)
        ->and($collaboration->getRawOriginal('permission'))->toBe(CollaborationPermission::Edit->value);
});

test('task collaborations relationship returns attached collaborations', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    $task->load('collaborations');
    expect($task->collaborations)->toHaveCount(1)
        ->and($task->collaborations->first()->id)->toBe($collaboration->id);
});

test('collaboration invitation factory creates with default status and token', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $this->owner->id,
        'invitee_email' => $this->collaborator->email,
        'invitee_user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::View,
    ]);

    expect($invitation->status)->toBe('pending')
        ->and($invitation->token)->not->toBeEmpty()
        ->and($invitation->exists)->toBeTrue();
});

test('collaboration invitation collaboratable returns correct task instance', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $this->owner->id,
        'invitee_email' => 'a@b.com',
    ]);

    expect($invitation->collaboratable)->toBeInstanceOf(Task::class)
        ->and($invitation->collaboratable->id)->toBe($task->id);
});

test('collaboration invitation inviter returns inviter user', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $this->owner->id,
        'invitee_email' => 'a@b.com',
    ]);

    expect($invitation->inviter)->not->toBeNull()
        ->and($invitation->inviter->id)->toBe($this->owner->id);
});

test('collaboration invitation invitee returns invitee user when set', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $this->owner->id,
        'invitee_email' => $this->collaborator->email,
        'invitee_user_id' => $this->collaborator->id,
    ]);

    expect($invitation->invitee)->not->toBeNull()
        ->and($invitation->invitee->id)->toBe($this->collaborator->id);
});

test('collaboration invitation permission is cast to enum', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $this->owner->id,
        'invitee_email' => 'a@b.com',
        'permission' => CollaborationPermission::Edit,
    ]);

    expect($invitation->permission)->toBe(CollaborationPermission::Edit);
});
