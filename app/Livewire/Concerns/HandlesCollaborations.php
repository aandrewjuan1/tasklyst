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
     * @param  array<string, mixed>  $payload
     */
    #[Async]
    #[Renderless]
    public function inviteCollaborator(array $payload): void
    {
        $user = $this->requireAuth(__('You must be logged in to invite collaborators.'));
        if ($user === null) {
            return;
        }

        $payload = array_replace_recursive(CollaborationPayloadValidation::createDefaults(), $payload);

        $validator = Validator::make(['collaborationPayload' => $payload], CollaborationPayloadValidation::createRules());
        if ($validator->fails()) {
            $this->dispatch('toast', type: 'error', message: $validator->errors()->first() ?: __('Invalid invite details.'));

            return;
        }

        $validated = $validator->validated()['collaborationPayload'];

        $invitee = User::query()->where('email', $validated['email'])->first();
        if ($invitee === null) {
            $this->dispatch('toast', type: 'error', message: __('User not found.'));

            return;
        }

        if ($invitee->id === $user->id) {
            $this->dispatch('toast', type: 'error', message: __('You cannot invite yourself.'));

            return;
        }

        $collaboratable = match ($validated['collaboratableType']) {
            'task' => Task::query()->forUser($user->id)->find((int) $validated['collaboratableId']),
            'project' => Project::query()->forUser($user->id)->find((int) $validated['collaboratableId']),
            'event' => Event::query()->forUser($user->id)->find((int) $validated['collaboratableId']),
            default => null,
        };

        if ($collaboratable === null) {
            $this->dispatch('toast', type: 'error', message: __('Item not found.'));

            return;
        }

        $this->authorize('update', $collaboratable);

        $exists = $collaboratable->collaborations()
            ->where('user_id', $invitee->id)
            ->exists();

        if ($exists) {
            $this->dispatch('toast', type: 'error', message: __('This user is already a collaborator.'));

            return;
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

            return;
        }

        $this->dispatch('collaborator-invited');
        $this->dispatch('toast', type: 'success', message: __('Collaborator invited.'));
        $this->dispatch('$refresh');
    }

    /**
     * Remove a collaborator from a task, project, or event.
     */
    #[Async]
    #[Renderless]
    public function removeCollaborator(int $collaborationId): void
    {
        $user = $this->requireAuth(__('You must be logged in to remove collaborators.'));
        if ($user === null) {
            return;
        }

        $collaboration = Collaboration::query()->with('collaboratable')->find($collaborationId);
        if ($collaboration === null) {
            $this->dispatch('toast', type: 'error', message: __('Collaboration not found.'));

            return;
        }

        $collaboratable = $collaboration->collaboratable;
        if ($collaboratable === null) {
            $this->dispatch('toast', type: 'error', message: __('Item not found.'));

            return;
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

            return;
        }

        if (! $deleted) {
            $this->dispatch('toast', type: 'error', message: __('Could not remove collaborator. Please try again.'));

            return;
        }

        $this->dispatch('collaborator-removed');
        $this->dispatch('toast', type: 'success', message: __('Collaborator removed.'));
        $this->dispatch('$refresh');
    }
}
