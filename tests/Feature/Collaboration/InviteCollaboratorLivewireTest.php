<?php

use App\Enums\CollaborationPermission;
use App\Models\Collaboration;
use App\Models\CollaborationInvitation;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->invitee = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('invite collaborator with valid payload for task creates invitation and dispatches success toast', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    $countBefore = CollaborationInvitation::query()->count();
    $payload = [
        'collaboratableType' => 'task',
        'collaboratableId' => $task->id,
        'email' => $this->invitee->email,
        'permission' => CollaborationPermission::Edit->value,
    ];

    Livewire::test('pages::workspace.index')
        ->call('inviteCollaborator', $payload)
        ->assertDispatched('toast', type: 'success', message: __('Invitation sent.'));

    expect(CollaborationInvitation::query()->count())->toBe($countBefore + 1);
    $invitation = CollaborationInvitation::query()
        ->where('collaboratable_type', Task::class)
        ->where('collaboratable_id', $task->id)
        ->where('invitee_email', $this->invitee->email)
        ->where('status', 'pending')
        ->first();
    expect($invitation)->not->toBeNull()
        ->and($invitation->permission)->toBe(CollaborationPermission::Edit);
});

test('invite collaborator with valid payload for event creates invitation and dispatches success toast', function (): void {
    $this->actingAs($this->owner);
    $event = Event::factory()->for($this->owner)->create();
    $payload = [
        'collaboratableType' => 'event',
        'collaboratableId' => $event->id,
        'email' => $this->invitee->email,
        'permission' => CollaborationPermission::View->value,
    ];

    Livewire::test('pages::workspace.index')
        ->call('inviteCollaborator', $payload)
        ->assertDispatched('toast', type: 'success', message: __('Invitation sent.'));

    $invitation = CollaborationInvitation::query()
        ->where('collaboratable_type', Event::class)
        ->where('collaboratable_id', $event->id)
        ->where('invitee_email', $this->invitee->email)
        ->first();
    expect($invitation)->not->toBeNull();
});

test('invite collaborator with valid payload for project creates invitation and dispatches success toast', function (): void {
    $this->actingAs($this->owner);
    $project = Project::factory()->for($this->owner)->create();
    $payload = [
        'collaboratableType' => 'project',
        'collaboratableId' => $project->id,
        'email' => $this->invitee->email,
        'permission' => CollaborationPermission::Edit->value,
    ];

    Livewire::test('pages::workspace.index')
        ->call('inviteCollaborator', $payload)
        ->assertDispatched('toast', type: 'success', message: __('Invitation sent.'));

    $invitation = CollaborationInvitation::query()
        ->where('collaboratable_type', Project::class)
        ->where('collaboratable_id', $project->id)
        ->where('invitee_email', $this->invitee->email)
        ->first();
    expect($invitation)->not->toBeNull();
});

test('invite collaborator with invalid email does not create invitation and does not dispatch toast', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    $countBefore = CollaborationInvitation::query()->count();
    $payload = [
        'collaboratableType' => 'task',
        'collaboratableId' => $task->id,
        'email' => 'not-an-email',
        'permission' => CollaborationPermission::Edit->value,
    ];

    Livewire::test('pages::workspace.index')
        ->call('inviteCollaborator', $payload)
        ->assertNotDispatched('toast');

    expect(CollaborationInvitation::query()->count())->toBe($countBefore);
});

test('invite self does not create invitation and does not dispatch toast', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    $countBefore = CollaborationInvitation::query()->count();
    $payload = [
        'collaboratableType' => 'task',
        'collaboratableId' => $task->id,
        'email' => $this->owner->email,
        'permission' => CollaborationPermission::Edit->value,
    ];

    Livewire::test('pages::workspace.index')
        ->call('inviteCollaborator', $payload)
        ->assertNotDispatched('toast');

    expect(CollaborationInvitation::query()->count())->toBe($countBefore);
});

test('invite when user is already collaborator does not create invitation and does not dispatch toast', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->invitee->id,
        'permission' => CollaborationPermission::View,
    ]);
    $countBefore = CollaborationInvitation::query()->count();
    $payload = [
        'collaboratableType' => 'task',
        'collaboratableId' => $task->id,
        'email' => $this->invitee->email,
        'permission' => CollaborationPermission::Edit->value,
    ];

    Livewire::test('pages::workspace.index')
        ->call('inviteCollaborator', $payload)
        ->assertNotDispatched('toast');

    expect(CollaborationInvitation::query()->count())->toBe($countBefore);
});

test('invite when invitation already pending does not create duplicate and does not dispatch toast', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $this->owner->id,
        'invitee_email' => $this->invitee->email,
        'invitee_user_id' => $this->invitee->id,
        'permission' => CollaborationPermission::Edit,
        'status' => 'pending',
    ]);
    $countBefore = CollaborationInvitation::query()->count();
    $payload = [
        'collaboratableType' => 'task',
        'collaboratableId' => $task->id,
        'email' => $this->invitee->email,
        'permission' => CollaborationPermission::View->value,
    ];

    Livewire::test('pages::workspace.index')
        ->call('inviteCollaborator', $payload)
        ->assertNotDispatched('toast');

    expect(CollaborationInvitation::query()->count())->toBe($countBefore);
});

test('invite when email has no user does not create invitation and does not dispatch toast', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    $countBefore = CollaborationInvitation::query()->count();
    $payload = [
        'collaboratableType' => 'task',
        'collaboratableId' => $task->id,
        'email' => 'nobody@example.com',
        'permission' => CollaborationPermission::Edit->value,
    ];

    Livewire::test('pages::workspace.index')
        ->call('inviteCollaborator', $payload)
        ->assertNotDispatched('toast');

    expect(CollaborationInvitation::query()->count())->toBe($countBefore);
});

test('invite on other user task does not create invitation and does not dispatch toast', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $this->actingAs($this->otherUser);
    $countBefore = CollaborationInvitation::query()->count();
    $payload = [
        'collaboratableType' => 'task',
        'collaboratableId' => $task->id,
        'email' => $this->invitee->email,
        'permission' => CollaborationPermission::Edit->value,
    ];

    Livewire::test('pages::workspace.index')
        ->call('inviteCollaborator', $payload)
        ->assertNotDispatched('toast');

    expect(CollaborationInvitation::query()->count())->toBe($countBefore);
});

test('invite collaborator success path creates invitation and dispatches only success toast', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    $payload = [
        'collaboratableType' => 'task',
        'collaboratableId' => $task->id,
        'email' => $this->invitee->email,
        'permission' => CollaborationPermission::Edit->value,
    ];

    Livewire::test('pages::workspace.index')
        ->call('inviteCollaborator', $payload)
        ->assertDispatched('toast', type: 'success');

    expect(CollaborationInvitation::query()
        ->where('collaboratable_id', $task->id)
        ->where('invitee_email', $this->invitee->email)
        ->exists())->toBeTrue();
});

test('invite collaborator failure path does not create invitation and does not dispatch any toast', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    $payload = [
        'collaboratableType' => 'task',
        'collaboratableId' => $task->id,
        'email' => $this->owner->email,
        'permission' => CollaborationPermission::Edit->value,
    ];

    Livewire::test('pages::workspace.index')
        ->call('inviteCollaborator', $payload)
        ->assertNotDispatched('toast');

    expect(CollaborationInvitation::query()
        ->where('collaboratable_id', $task->id)
        ->where('invitee_email', $this->owner->email)
        ->exists())->toBeFalse();
});
