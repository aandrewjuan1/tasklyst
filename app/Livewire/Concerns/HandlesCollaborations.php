<?php

namespace App\Livewire\Concerns;

use App\DataTransferObjects\Collaboration\CreateCollaborationInvitationDto;
use App\Enums\CollaborationPermission;
use App\Models\Collaboration;
use App\Models\CollaborationInvitation;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Validation\CollaborationPayloadValidation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Async;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Renderless;

trait HandlesCollaborations
{
    /**
     * Pending collaboration invitations for the current user (invitee).
     * Used by the pending-invitations popover for accept/decline UI.
     *
     * @return \Illuminate\Support\Collection<int, array{token: string, id: int, item_title: string, item_type: string, inviter_name: string, permission: string}>
     */
    #[Computed]
    public function pendingInvitationsForUser(): Collection
    {
        $user = Auth::user();
        if ($user === null) {
            return collect();
        }

        $invitations = CollaborationInvitation::query()
            ->pendingForUser($user)
            ->with(['collaboratable', 'inviter'])
            ->get();

        return $invitations->map(function (CollaborationInvitation $invitation): array {
            $collaboratable = $invitation->collaboratable;
            $itemTitle = $collaboratable !== null
                ? ($collaboratable->title ?? $collaboratable->name ?? (string) $collaboratable->id)
                : (string) $invitation->id;
            $itemType = match ($invitation->collaboratable_type) {
                Task::class => 'task',
                Event::class => 'event',
                Project::class => 'project',
                default => 'item',
            };
            $inviterName = $invitation->inviter?->name ?? $invitation->inviter?->email ?? __('Someone');

            $permissionEnum = $invitation->permission;
            $permissionLabel = match ($permissionEnum) {
                CollaborationPermission::Edit => __('Can edit'),
                CollaborationPermission::View, null => __('Can view'),
            };

            return [
                'token' => $invitation->token,
                'id' => $invitation->id,
                'item_title' => $itemTitle,
                'item_type' => $itemType,
                'inviter_name' => $inviterName,
                'permission' => $permissionLabel,
            ];
        })->values();
    }

    /**
     * Invite a collaborator to a task, project, or event.
     *
     * Returns a structured array so optimistic UIs can show inline errors
     * without toasts. Success toast is dispatched by this method.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, message?: string}
     */
    #[Async]
    #[Renderless]
    public function inviteCollaborator(array $payload): array
    {
        $user = $this->requireAuth(__('You must be logged in to invite collaborators.'));
        if ($user === null) {
            return ['success' => false, 'message' => __('You must be logged in to invite collaborators.')];
        }

        $payload = array_replace_recursive(CollaborationPayloadValidation::createDefaults(), $payload);

        $validator = Validator::make(
            ['collaborationPayload' => $payload],
            CollaborationPayloadValidation::createRules(),
            [
                'collaborationPayload.email.required' => __('Please enter an email address.'),
                'collaborationPayload.email.email' => __('Please enter a valid email address.'),
            ]
        );
        if ($validator->fails()) {
            $message = $validator->errors()->first() ?: __('Invalid invite details.');

            return ['success' => false, 'message' => $message];
        }

        $validated = $validator->validated()['collaborationPayload'];

        if (strcasecmp($validated['email'], $user->email) === 0) {
            return ['success' => false, 'message' => __('You cannot invite yourself.')];
        }

        $collaboratable = match ($validated['collaboratableType']) {
            'task' => Task::query()->forUser($user->id)->find((int) $validated['collaboratableId']),
            'project' => Project::query()->forUser($user->id)->find((int) $validated['collaboratableId']),
            'event' => Event::query()->forUser($user->id)->find((int) $validated['collaboratableId']),
            default => null,
        };

        if ($collaboratable === null) {
            return ['success' => false, 'message' => __('Item not found.')];
        }

        // Only the owner of the item can invite collaborators, even if collaborators can edit the item itself.
        if ((int) $collaboratable->user_id !== (int) $user->id) {
            return ['success' => false, 'message' => __('Only the owner can manage collaborators for this item.')];
        }

        $this->authorize('update', $collaboratable);

        $dto = CreateCollaborationInvitationDto::fromValidated($validated, $user->id);

        $invitee = User::query()
            ->where('email', $dto->inviteeEmail)
            ->first();

        if ($invitee === null) {
            return ['success' => false, 'message' => __('No user was found with that email address.')];
        }

        $collaboratableType = $dto->collaboratableMorphClass();

        $alreadyCollaborator = Collaboration::query()
            ->where('collaboratable_type', $collaboratableType)
            ->where('collaboratable_id', $dto->collaboratableId)
            ->where('user_id', $invitee->id)
            ->exists();

        if ($alreadyCollaborator) {
            return ['success' => false, 'message' => __('This user is already a collaborator on this item.')];
        }

        $alreadyInvited = CollaborationInvitation::query()
            ->where('collaboratable_type', $collaboratableType)
            ->where('collaboratable_id', $dto->collaboratableId)
            ->where('invitee_email', $dto->inviteeEmail)
            ->where('status', 'pending')
            ->exists();

        if ($alreadyInvited) {
            return ['success' => false, 'message' => __('An invitation has already been sent to this email for this item.')];
        }

        try {
            $invitation = $this->createCollaborationInvitationAction->execute($dto);
        } catch (\Throwable $e) {
            Log::error('Failed to create collaboration invitation from workspace.', [
                'user_id' => $user->id,
                'exception' => $e,
            ]);

            return ['success' => false, 'message' => __('Could not send invitation. Please try again.')];
        }

        $this->dispatch('collaboration-invitation-created');
        $this->dispatch('toast', type: 'success', message: __('Invitation sent.'));

        return ['success' => true, 'invitationId' => $invitation->id];
    }

    /**
     * Remove a collaborator from a task, project, or event.
     *
     * Returns a boolean so frontend code can rollback optimistic state on failure.
     */
    #[Async]
    #[Renderless]
    public function removeCollaborator(int $collaborationId): bool
    {
        $user = $this->requireAuth(__('You must be logged in to remove collaborators.'));
        if ($user === null) {
            return false;
        }

        $collaboration = Collaboration::query()->with('collaboratable')->find($collaborationId);
        if ($collaboration === null) {
            $this->dispatch('toast', type: 'error', message: __('Collaboration not found.'));

            return false;
        }

        $collaboratable = $collaboration->collaboratable;
        if ($collaboratable === null) {
            $this->dispatch('toast', type: 'error', message: __('Item not found.'));

            return false;
        }

        $this->authorize('delete', $collaboration);

        try {
            $deleted = $this->deleteCollaborationAction->execute($collaboration);
        } catch (\Throwable $e) {
            Log::error('Failed to remove collaborator from workspace.', [
                'user_id' => $user->id,
                'collaboration_id' => $collaborationId,
                'exception' => $e,
            ]);
            $this->dispatch('toast', type: 'error', message: __('Could not remove collaborator. Please try again.'));

            return false;
        }

        if (! $deleted) {
            $this->dispatch('toast', type: 'error', message: __('Could not remove collaborator. Please try again.'));

            return false;
        }

        $this->dispatch('collaborator-removed');
        $this->dispatch('toast', type: 'success', message: __('Collaborator removed.'));

        return true;
    }

    /**
     * Allow a collaborator to leave a task, project, or event by removing their own collaboration.
     *
     * Returns a boolean so frontend code can rollback optimistic state on failure.
     */
    #[Async]
    #[Renderless]
    public function leaveCollaboration(int $collaborationId): bool
    {
        $user = $this->requireAuth(__('You must be logged in to leave this item.'));
        if ($user === null) {
            return false;
        }

        $collaboration = Collaboration::query()->with('collaboratable')->find($collaborationId);
        if ($collaboration === null) {
            $this->dispatch('toast', type: 'error', message: __('Collaboration not found.'));

            return false;
        }

        $collaboratable = $collaboration->collaboratable;
        if ($collaboratable === null) {
            $this->dispatch('toast', type: 'error', message: __('Item not found.'));

            return false;
        }

        $this->authorize('leave', $collaboration);

        try {
            $deleted = $this->deleteCollaborationAction->execute($collaboration);
        } catch (\Throwable $e) {
            Log::error('Failed to leave collaboration from workspace.', [
                'user_id' => $user->id,
                'collaboration_id' => $collaborationId,
                'exception' => $e,
            ]);
            $this->dispatch('toast', type: 'error', message: __('Could not leave this item. Please try again.'));

            return false;
        }

        if (! $deleted) {
            $this->dispatch('toast', type: 'error', message: __('Could not leave this item. Please try again.'));

            return false;
        }

        $this->dispatch('collaborator-removed');
        $this->dispatch('toast', type: 'success', message: __('You left this item.'));
        $this->incrementListRefresh();

        return true;
    }

    /**
     * Delete a collaboration invitation by ID.
     *
     * Mirrors removeCollaborator so optimistic UIs can rollback on failure.
     */
    #[Async]
    #[Renderless]
    public function deleteCollaborationInvitation(int $invitationId): bool
    {
        $user = $this->requireAuth(__('You must be logged in to remove invitations.'));
        if ($user === null) {
            return false;
        }

        $invitation = CollaborationInvitation::query()
            ->with('collaboratable')
            ->find($invitationId);

        if ($invitation === null) {
            $this->dispatch('toast', type: 'error', message: __('Invitation not found.'));

            return false;
        }

        $collaboratable = $invitation->collaboratable;
        if ($collaboratable === null) {
            $this->dispatch('toast', type: 'error', message: __('Item not found.'));

            return false;
        }

        $this->authorize('delete', $invitation);

        try {
            $deleted = (bool) $invitation->delete();
        } catch (\Throwable $e) {
            Log::error('Failed to delete collaboration invitation from workspace.', [
                'user_id' => $user->id,
                'invitation_id' => $invitationId,
                'exception' => $e,
            ]);
            $this->dispatch('toast', type: 'error', message: __('Could not remove invitation. Please try again.'));

            return false;
        }

        if (! $deleted) {
            $this->dispatch('toast', type: 'error', message: __('Could not remove invitation. Please try again.'));

            return false;
        }

        $this->dispatch('toast', type: 'success', message: __('Invitation removed.'));

        return true;
    }

    public function acceptCollaborationInvitation(string $token): bool
    {
        $user = $this->requireAuth(__('You must be logged in to accept invitations.'));
        if ($user === null) {
            return false;
        }

        $invitation = CollaborationInvitation::query()->with('collaboratable')->where('token', $token)->first();
        if ($invitation === null) {
            $this->dispatch('toast', type: 'error', message: __('Invitation not found or already handled.'));

            return false;
        }

        if ($invitation->collaboratable === null) {
            $this->dispatch('toast', type: 'error', message: __('Item not found.'));

            return false;
        }

        $this->authorize('accept', $invitation);

        $result = $this->acceptCollaborationInvitationAction->execute($invitation, $user);
        if ($result === null && $invitation->status !== 'accepted') {
            $this->dispatch('toast', type: 'error', message: __('Could not accept invitation. Please try again.'));

            return false;
        }

        $this->dispatch('collaboration-invitation-accepted');
        $this->dispatch('toast', type: 'success', message: __('Invitation accepted.'));
        $this->incrementListRefresh();

        return true;
    }

    #[Async]
    #[Renderless]
    public function declineCollaborationInvitation(string $token): bool
    {
        $user = $this->requireAuth(__('You must be logged in to decline invitations.'));
        if ($user === null) {
            return false;
        }

        $invitation = CollaborationInvitation::query()->where('token', $token)->first();
        if ($invitation === null) {
            $this->dispatch('toast', type: 'error', message: __('Invitation not found or already handled.'));

            return false;
        }

        $this->authorize('decline', $invitation);

        $ok = $this->declineCollaborationInvitationAction->execute($invitation, $user);
        if (! $ok) {
            $this->dispatch('toast', type: 'error', message: __('Could not decline invitation. Please try again.'));

            return false;
        }

        $this->dispatch('collaboration-invitation-declined');
        $this->dispatch('toast', type: 'success', message: __('Invitation declined.'));

        return true;
    }

    /**
     * Update the permission of an existing collaborator (view ↔ edit).
     */
    #[Async]
    #[Renderless]
    public function updateCollaboratorPermission(int $collaborationId, string $permission): bool
    {
        $user = $this->requireAuth(__('You must be logged in to update collaborator permissions.'));
        if ($user === null) {
            return false;
        }

        $collaboration = Collaboration::query()->with('collaboratable')->find($collaborationId);
        if ($collaboration === null) {
            $this->dispatch('toast', type: 'error', message: __('Collaboration not found.'));

            return false;
        }

        $collaboratable = $collaboration->collaboratable;
        if ($collaboratable === null) {
            $this->dispatch('toast', type: 'error', message: __('Item not found.'));

            return false;
        }

        $this->authorize('update', $collaboration);

        $enum = CollaborationPermission::tryFrom($permission);
        if ($enum === null) {
            $this->dispatch('toast', type: 'error', message: __('Invalid permission.'));

            return false;
        }

        try {
            $this->updateCollaborationPermissionAction->execute($collaboration, $enum);
        } catch (\Throwable $e) {
            Log::error('Failed to update collaboration permission from workspace.', [
                'user_id' => $user->id,
                'collaboration_id' => $collaborationId,
                'exception' => $e,
            ]);
            $this->dispatch('toast', type: 'error', message: __('Could not update collaborator permission. Please try again.'));

            return false;
        }

        $this->dispatch('collaborator-permission-updated');
        $this->dispatch('toast', type: 'success', message: __('Collaborator permission updated.'));

        return true;
    }
}
