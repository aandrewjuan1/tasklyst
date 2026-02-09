<?php

namespace App\Livewire\Concerns;

use App\DataTransferObjects\Collaboration\CreateCollaborationInvitationDto;
use App\Enums\CollaborationPermission;
use App\Models\Collaboration;
use App\Models\CollaborationInvitation;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Support\Validation\CollaborationPayloadValidation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Async;
use Livewire\Attributes\Renderless;

trait HandlesCollaborations
{
    /**
     * Invite a collaborator to a task, project, or event.
     *
     * Mirrors other workspace handlers by returning a boolean status so
     * optimistic UIs can respond consistently to success / failure.
     *
     * @param  array<string, mixed>  $payload
     */
    #[Async]
    #[Renderless]
    public function inviteCollaborator(array $payload): bool
    {
        $user = $this->requireAuth(__('You must be logged in to invite collaborators.'));
        if ($user === null) {
            return false;
        }

        $payload = array_replace_recursive(CollaborationPayloadValidation::createDefaults(), $payload);

        $validator = Validator::make(['collaborationPayload' => $payload], CollaborationPayloadValidation::createRules());
        if ($validator->fails()) {
            $this->dispatch('toast', type: 'error', message: $validator->errors()->first() ?: __('Invalid invite details.'));

            return false;
        }

        $validated = $validator->validated()['collaborationPayload'];

        if (strcasecmp($validated['email'], $user->email) === 0) {
            $this->dispatch('toast', type: 'error', message: __('You cannot invite yourself.'));

            return false;
        }

        $collaboratable = match ($validated['collaboratableType']) {
            'task' => Task::query()->forUser($user->id)->find((int) $validated['collaboratableId']),
            'project' => Project::query()->forUser($user->id)->find((int) $validated['collaboratableId']),
            'event' => Event::query()->forUser($user->id)->find((int) $validated['collaboratableId']),
            default => null,
        };

        if ($collaboratable === null) {
            $this->dispatch('toast', type: 'error', message: __('Item not found.'));

            return false;
        }

        $this->authorize('update', $collaboratable);

        $dto = CreateCollaborationInvitationDto::fromValidated($validated, $user->id);

        try {
            $this->createCollaborationInvitationAction->execute($dto);
        } catch (\Throwable $e) {
            Log::error('Failed to create collaboration invitation from workspace.', [
                'user_id' => $user->id,
                'exception' => $e,
            ]);
            $this->dispatch('toast', type: 'error', message: __('Could not send invitation. Please try again.'));

            return false;
        }

        $this->dispatch('collaboration-invitation-created');
        $this->dispatch('toast', type: 'success', message: __('Invitation sent.'));

        return true;
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

        $this->authorize('update', $collaboratable);

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

    #[Async]
    #[Renderless]
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

        $result = $this->acceptCollaborationInvitationAction->execute($invitation, $user);
        if ($result === null && $invitation->status !== 'accepted') {
            $this->dispatch('toast', type: 'error', message: __('Could not accept invitation. Please try again.'));

            return false;
        }

        $this->dispatch('collaboration-invitation-accepted');
        $this->dispatch('toast', type: 'success', message: __('Invitation accepted.'));

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

        $this->authorize('update', $collaboratable);

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
