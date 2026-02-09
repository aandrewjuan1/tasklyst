<?php

namespace App\Livewire\Concerns;

use App\DataTransferObjects\Collaboration\CreateCollaborationDto;
use App\Models\Collaboration;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
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

        $invitee = User::query()->where('email', $validated['email'])->first();
        if ($invitee === null) {
            $this->dispatch('toast', type: 'error', message: __('User not found.'));

            return false;
        }

        if ($invitee->id === $user->id) {
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

        $exists = $collaboratable->collaborations()
            ->where('user_id', $invitee->id)
            ->exists();

        if ($exists) {
            $this->dispatch('toast', type: 'error', message: __('This user is already a collaborator.'));

            return false;
        }

        $validated['userId'] = $invitee->id;
        $dto = CreateCollaborationDto::fromValidated($validated);

        try {
            $this->createCollaborationAction->execute($dto);
        } catch (\Throwable $e) {
            Log::error('Failed to invite collaborator from workspace.', [
                'user_id' => $user->id,
                'exception' => $e,
            ]);
            $this->dispatch('toast', type: 'error', message: __('Could not invite collaborator. Please try again.'));

            return false;
        }

        $this->dispatch('collaborator-invited');
        $this->dispatch('toast', type: 'success', message: __('Collaborator invited.'));

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
}
