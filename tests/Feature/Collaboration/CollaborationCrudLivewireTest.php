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
    $this->collaborator = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('owner can remove collaborator and collaboration is removed', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::View,
    ]);
    $collaborationId = $collaboration->id;

    Livewire::test('pages::workspace.index')
        ->call('removeCollaborator', $collaborationId)
        ->assertDispatched('toast', type: 'success', message: __('Collaborator removed.'));

    expect(Collaboration::find($collaborationId))->toBeNull();
});

test('owner can remove collaborator on event', function (): void {
    $this->actingAs($this->owner);
    $event = Event::factory()->for($this->owner)->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Event::class,
        'collaboratable_id' => $event->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    Livewire::test('pages::workspace.index')
        ->call('removeCollaborator', $collaboration->id);

    expect(Collaboration::find($collaboration->id))->toBeNull();
});

test('owner can remove collaborator on project', function (): void {
    $this->actingAs($this->owner);
    $project = Project::factory()->for($this->owner)->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Project::class,
        'collaboratable_id' => $project->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::View,
    ]);

    Livewire::test('pages::workspace.index')
        ->call('removeCollaborator', $collaboration->id);

    expect(Collaboration::find($collaboration->id))->toBeNull();
});

test('other user cannot remove collaborator on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::View,
    ]);
    $this->actingAs($this->otherUser);

    Livewire::test('pages::workspace.index')
        ->call('removeCollaborator', $collaboration->id)
        ->assertForbidden();

    expect(Collaboration::find($collaboration->id))->not->toBeNull();
});

test('remove collaborator with non existent id does not throw', function (): void {
    $this->actingAs($this->owner);
    $countBefore = Collaboration::query()->count();

    Livewire::test('pages::workspace.index')
        ->call('removeCollaborator', 99999);

    expect(Collaboration::query()->count())->toBe($countBefore);
});

test('unauthenticated remove collaborator does not remove', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::View,
    ]);

    Livewire::test('pages::workspace.index')
        ->call('removeCollaborator', $collaboration->id);

    expect(Collaboration::find($collaboration->id))->not->toBeNull();
});

test('owner can delete collaboration invitation and invitation is removed', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $this->owner->id,
        'invitee_email' => $this->collaborator->email,
        'status' => 'pending',
    ]);
    $invitationId = $invitation->id;

    Livewire::test('pages::workspace.index')
        ->call('deleteCollaborationInvitation', $invitationId)
        ->assertDispatched('toast', type: 'success', message: __('Invitation removed.'));

    expect(CollaborationInvitation::find($invitationId))->toBeNull();
});

test('other user cannot delete collaboration invitation on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $this->owner->id,
        'invitee_email' => $this->collaborator->email,
        'status' => 'pending',
    ]);
    $this->actingAs($this->otherUser);

    Livewire::test('pages::workspace.index')
        ->call('deleteCollaborationInvitation', $invitation->id)
        ->assertForbidden();

    expect(CollaborationInvitation::find($invitation->id))->not->toBeNull();
});

test('delete collaboration invitation with non existent id does not throw', function (): void {
    $this->actingAs($this->owner);
    $countBefore = CollaborationInvitation::query()->count();

    Livewire::test('pages::workspace.index')
        ->call('deleteCollaborationInvitation', 99999);

    expect(CollaborationInvitation::query()->count())->toBe($countBefore);
});

test('unauthenticated delete collaboration invitation does not delete', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $this->owner->id,
        'invitee_email' => $this->collaborator->email,
        'status' => 'pending',
    ]);

    Livewire::test('pages::workspace.index')
        ->call('deleteCollaborationInvitation', $invitation->id);

    expect(CollaborationInvitation::find($invitation->id))->not->toBeNull();
});

test('owner can update collaborator permission from view to edit', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::View,
    ]);

    Livewire::test('pages::workspace.index')
        ->call('updateCollaboratorPermission', $collaboration->id, 'edit')
        ->assertDispatched('toast', type: 'success', message: __('Collaborator permission updated.'));

    expect($collaboration->fresh()->permission)->toBe(CollaborationPermission::Edit);
});

test('owner can update collaborator permission from edit to view', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    Livewire::test('pages::workspace.index')
        ->call('updateCollaboratorPermission', $collaboration->id, 'view');

    expect($collaboration->fresh()->permission)->toBe(CollaborationPermission::View);
});

test('other user cannot update collaborator permission on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::View,
    ]);
    $this->actingAs($this->otherUser);

    Livewire::test('pages::workspace.index')
        ->call('updateCollaboratorPermission', $collaboration->id, 'edit')
        ->assertForbidden();

    expect($collaboration->fresh()->permission)->toBe(CollaborationPermission::View);
});

test('update collaborator permission with invalid permission does not update', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::View,
    ]);

    Livewire::test('pages::workspace.index')
        ->call('updateCollaboratorPermission', $collaboration->id, 'invalid');

    expect($collaboration->fresh()->permission)->toBe(CollaborationPermission::View);
});

test('update collaborator permission with non existent id does not throw', function (): void {
    $this->actingAs($this->owner);
    $countBefore = Collaboration::query()->count();

    Livewire::test('pages::workspace.index')
        ->call('updateCollaboratorPermission', 99999, 'edit');

    expect(Collaboration::query()->count())->toBe($countBefore);
});

test('unauthenticated update collaborator permission does not update', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::View,
    ]);

    Livewire::test('pages::workspace.index')
        ->call('updateCollaboratorPermission', $collaboration->id, 'edit');

    expect($collaboration->fresh()->permission)->toBe(CollaborationPermission::View);
});

test('collaborator with edit permission cannot remove other collaborator on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::Edit,
    ]);
    $collabToRemove = User::factory()->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $collabToRemove->id,
        'permission' => CollaborationPermission::View,
    ]);
    $this->actingAs($this->collaborator);

    Livewire::test('pages::workspace.index')
        ->call('removeCollaborator', $collaboration->id)
        ->assertForbidden();

    expect(Collaboration::find($collaboration->id))->not->toBeNull();
});

test('collaborator with edit permission cannot update collaborator permission on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::Edit,
    ]);
    $otherCollab = User::factory()->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $otherCollab->id,
        'permission' => CollaborationPermission::View,
    ]);
    $this->actingAs($this->collaborator);

    Livewire::test('pages::workspace.index')
        ->call('updateCollaboratorPermission', $collaboration->id, 'edit')
        ->assertForbidden();

    expect($collaboration->fresh()->permission)->toBe(CollaborationPermission::View);
});

test('collaborator can leave their own collaboration on task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    $this->actingAs($this->collaborator);

    Livewire::test('pages::workspace.index')
        ->call('leaveCollaboration', $collaboration->id)
        ->assertDispatched('toast', type: 'success', message: __('You left this item.'));

    expect(Collaboration::find($collaboration->id))->toBeNull();
});

test('owner cannot call leaveCollaboration for another users collaboration', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::View,
    ]);

    $this->actingAs($this->owner);

    Livewire::test('pages::workspace.index')
        ->call('leaveCollaboration', $collaboration->id)
        ->assertForbidden();

    expect(Collaboration::find($collaboration->id))->not->toBeNull();
});

test('other user cannot leave another users collaboration', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::View,
    ]);

    $this->actingAs($this->otherUser);

    Livewire::test('pages::workspace.index')
        ->call('leaveCollaboration', $collaboration->id)
        ->assertForbidden();

    expect(Collaboration::find($collaboration->id))->not->toBeNull();
});

test('unauthenticated user cannot leave collaboration', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $collaboration = Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::View,
    ]);

    Livewire::test('pages::workspace.index')
        ->call('leaveCollaboration', $collaboration->id);

    expect(Collaboration::find($collaboration->id))->not->toBeNull();
});

test('invitee can accept collaboration invitation and becomes collaborator', function (): void {
    $this->actingAs($this->collaborator);

    $task = Task::factory()->for($this->owner)->create();

    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $this->owner->id,
        'invitee_email' => $this->collaborator->email,
        'invitee_user_id' => null,
        'permission' => CollaborationPermission::Edit,
        'status' => 'pending',
    ]);

    Livewire::test('pages::workspace.index')
        ->call('acceptCollaborationInvitation', $invitation->token)
        ->assertDispatched('toast', type: 'success', message: __('Invitation accepted.'));

    $invitation->refresh();

    expect($invitation->status)->toBe('accepted')
        ->and($invitation->invitee_user_id)->toBe($this->collaborator->id)
        ->and(
            Collaboration::query()
                ->where('collaboratable_type', Task::class)
                ->where('collaboratable_id', $task->id)
                ->where('user_id', $this->collaborator->id)
                ->where('permission', CollaborationPermission::Edit)
                ->exists()
        )->toBeTrue();
});

test('other user cannot accept collaboration invitation they did not receive', function (): void {
    $task = Task::factory()->for($this->owner)->create();

    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $this->owner->id,
        'invitee_email' => $this->collaborator->email,
        'invitee_user_id' => null,
        'permission' => CollaborationPermission::View,
        'status' => 'pending',
    ]);

    $this->actingAs($this->otherUser);

    Livewire::test('pages::workspace.index')
        ->call('acceptCollaborationInvitation', $invitation->token)
        ->assertForbidden();

    $invitation->refresh();

    expect($invitation->status)->toBe('pending')
        ->and(
            Collaboration::query()
                ->where('collaboratable_type', Task::class)
                ->where('collaboratable_id', $task->id)
                ->where('user_id', $this->otherUser->id)
                ->exists()
        )->toBeFalse();
});

test('unauthenticated user cannot accept collaboration invitation', function (): void {
    $task = Task::factory()->for($this->owner)->create();

    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $this->owner->id,
        'invitee_email' => $this->collaborator->email,
        'invitee_user_id' => null,
        'permission' => CollaborationPermission::View,
        'status' => 'pending',
    ]);

    Livewire::test('pages::workspace.index')
        ->call('acceptCollaborationInvitation', $invitation->token);

    $invitation->refresh();

    expect($invitation->status)->toBe('pending')
        ->and(
            Collaboration::query()
                ->where('collaboratable_type', Task::class)
                ->where('collaboratable_id', $task->id)
                ->where('user_id', $this->collaborator->id)
                ->exists()
        )->toBeFalse();
});

test('invitee can decline collaboration invitation and it is not accepted', function (): void {
    $this->actingAs($this->collaborator);

    $task = Task::factory()->for($this->owner)->create();

    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $this->owner->id,
        'invitee_email' => $this->collaborator->email,
        'invitee_user_id' => null,
        'permission' => CollaborationPermission::View,
        'status' => 'pending',
    ]);

    Livewire::test('pages::workspace.index')
        ->call('declineCollaborationInvitation', $invitation->token)
        ->assertDispatched('toast', type: 'success', message: __('Invitation declined.'));

    $invitation->refresh();

    expect($invitation->status)->toBe('declined')
        ->and($invitation->invitee_user_id)->toBe($this->collaborator->id)
        ->and(
            Collaboration::query()
                ->where('collaboratable_type', Task::class)
                ->where('collaboratable_id', $task->id)
                ->where('user_id', $this->collaborator->id)
                ->exists()
        )->toBeFalse();
});

test('other user cannot decline collaboration invitation they did not receive', function (): void {
    $task = Task::factory()->for($this->owner)->create();

    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $this->owner->id,
        'invitee_email' => $this->collaborator->email,
        'invitee_user_id' => null,
        'permission' => CollaborationPermission::View,
        'status' => 'pending',
    ]);

    $this->actingAs($this->otherUser);

    Livewire::test('pages::workspace.index')
        ->call('declineCollaborationInvitation', $invitation->token)
        ->assertForbidden();

    $invitation->refresh();

    expect($invitation->status)->toBe('pending');
});

test('unauthenticated user cannot decline collaboration invitation', function (): void {
    $task = Task::factory()->for($this->owner)->create();

    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $this->owner->id,
        'invitee_email' => $this->collaborator->email,
        'invitee_user_id' => null,
        'permission' => CollaborationPermission::View,
        'status' => 'pending',
    ]);

    Livewire::test('pages::workspace.index')
        ->call('declineCollaborationInvitation', $invitation->token);

    $invitation->refresh();

    expect($invitation->status)->toBe('pending');
});
