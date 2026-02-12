<?php

namespace App\Livewire\Concerns;

use App\DataTransferObjects\Project\CreateProjectDto;
use App\Models\Project;
use App\Models\User;
use App\Support\Validation\ProjectPayloadValidation;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Async;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Renderless;

trait HandlesProjects
{
    /**
     * Create a new project for the authenticated user.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createProject(array $payload): void
    {
        $user = $this->requireAuth(__('You must be logged in to create projects.'));
        if ($user === null) {
            return;
        }

        $this->authorize('create', Project::class);

        $this->projectPayload = array_replace_recursive(ProjectPayloadValidation::defaults(), $payload);

        try {
            /** @var array{projectPayload: array<string, mixed>} $validated */
            $validated = $this->validate(ProjectPayloadValidation::rules());
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Project validation failed', [
                'errors' => $e->errors(),
                'payload' => $this->projectPayload,
            ]);
            $this->dispatch('toast', type: 'error', message: __('Please fix the project details and try again.'));

            return;
        }

        $validatedProject = $validated['projectPayload'];
        $dto = CreateProjectDto::fromValidated($validatedProject);

        try {
            $project = $this->createProjectAction->execute($user, $dto);
        } catch (\Throwable $e) {
            Log::error('Failed to create project from workspace.', [
                'user_id' => $user->id,
                'payload' => $this->projectPayload,
                'exception' => $e,
            ]);

            $this->dispatch('toast', ...Project::toastPayload('create', false, $dto->name));

            return;
        }

        $this->listRefresh++;
        $this->dispatch('project-created', id: $project->id, name: $project->name);
        $this->dispatch('toast', ...Project::toastPayload('create', true, $project->name));
    }

    /**
     * Delete a project for the authenticated user.
     */
    #[Async]
    #[Renderless]
    public function deleteProject(int $projectId): bool
    {
        $user = $this->requireAuth(__('You must be logged in to delete projects.'));
        if ($user === null) {
            return false;
        }

        $project = Project::query()->forUser($user->id)->find($projectId);

        if ($project === null) {
            $this->dispatch('toast', type: 'error', message: __('Project not found.'));

            return false;
        }

        if ((int) $project->user_id !== (int) $user->id) {
            $this->dispatch('toast', type: 'error', message: __('Only the owner can delete this project.'));

            return false;
        }

        $this->authorize('delete', $project);

        try {
            $deleted = $this->deleteProjectAction->execute($project, $user);
        } catch (\Throwable $e) {
            Log::error('Failed to delete project from workspace.', [
                'user_id' => $user->id,
                'project_id' => $projectId,
                'exception' => $e,
            ]);

            $this->dispatch('toast', ...Project::toastPayload('delete', false, $project->name));

            return false;
        }

        if (! $deleted) {
            $this->dispatch('toast', ...Project::toastPayload('delete', false, $project->name));

            return false;
        }

        $this->dispatch('toast', ...Project::toastPayload('delete', true, $project->name));

        return true;
    }

    /**
     * Update a single project property for the authenticated user (inline editing).
     *
     * @param  bool  $silentToasts  When true, do not dispatch success toast (e.g. when syncing tagIds after delete so only "Tag deleted." is shown).
     */
    #[Async]
    #[Renderless]
    public function updateProjectProperty(int $projectId, string $property, mixed $value, bool $silentToasts = false): bool
    {
        $user = $this->requireAuth(__('You must be logged in to update projects.'));
        if ($user === null) {
            return false;
        }

        $project = Project::query()->forUser($user->id)->find($projectId);

        if ($project === null) {
            $this->dispatch('toast', type: 'error', message: __('Project not found.'));

            return false;
        }

        $this->authorize('update', $project);

        // Only the owner can change date fields, even if collaborators can edit other properties.
        $isOwner = (int) $project->user_id === (int) $user->id;
        if (! $isOwner && in_array($property, ['startDatetime', 'endDatetime'], true)) {
            $this->dispatch('toast', type: 'error', message: __('Only the owner can change dates for this project.'));

            return false;
        }

        if (! in_array($property, ProjectPayloadValidation::allowedUpdateProperties(), true)) {
            $this->dispatch('toast', type: 'error', message: __('Invalid property for update.'));

            return false;
        }

        $rules = ProjectPayloadValidation::rulesForProperty($property);
        if ($rules === []) {
            $this->dispatch('toast', type: 'error', message: __('Invalid property for update.'));

            return false;
        }

        // Explicit validation for name property - reject empty/whitespace-only values before validator
        if ($property === 'name') {
            $trimmedValue = is_string($value) ? trim($value) : $value;
            if (empty($trimmedValue)) {
                $this->dispatch('toast', type: 'error', message: __('Title cannot be empty.'));

                return false;
            }
            $value = $trimmedValue;
        }

        $validator = Validator::make(['value' => $value], $rules);
        if ($validator->fails()) {
            $this->dispatch('toast', type: 'error', message: $validator->errors()->first('value') ?: __('Invalid value.'));

            return false;
        }

        $validatedValue = $validator->validated()['value'];

        $result = $this->updateProjectPropertyAction->execute($project, $property, $validatedValue);

        if (! $result->success) {
            if ($result->errorMessage !== null) {
                $this->dispatch('toast', type: 'error', message: $result->errorMessage);
            } else {
                $this->dispatch('toast', ...Project::toastPayloadForPropertyUpdate($property, $result->oldValue, $result->newValue, false, $project->name));
            }

            return false;
        }

        if (! $silentToasts) {
            $this->dispatch('toast', ...Project::toastPayloadForPropertyUpdate($property, $result->oldValue, $result->newValue, true, $project->name));
        }

        return true;
    }

    /**
     * Get projects for the selected date for the authenticated user.
     */
    #[Computed]
    public function projects(): Collection
    {
        $userId = Auth::id();

        if ($userId === null) {
            return collect();
        }

        $filterItemType = property_exists($this, 'filterItemType') ? $this->normalizeFilterValue($this->filterItemType) : null;
        if ($filterItemType !== null && $filterItemType !== 'projects') {
            return collect();
        }

        $date = Carbon::parse($this->selectedDate);

        return Project::query()
            ->with([
                'user',
                'tasks',
                'collaborations',
                'collaborators',
                'collaborationInvitations.invitee',
                'comments.user',
            ])
            ->withRecentActivityLogs(5)
            ->forUser($userId)
            ->notArchived()
            ->activeForDate($date)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
    }
}
