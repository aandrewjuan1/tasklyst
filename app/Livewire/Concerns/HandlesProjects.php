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
     * Pagination settings for workspace project list.
     */
    public int $projectsPerPage = 10;

    public int $projectsPage = 1;

    public bool $hasMoreProjects = false;

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

    /** REMOVED restoreProject - use HandlesTrash::restoreTrashItem */
    private function _removedRestoreProjectStub(int $projectId): bool
    {
        $user = $this->requireAuth(__('You must be logged in to restore projects.'));
        if ($user === null) {
            return false;
        }

        $project = Project::query()->onlyTrashed()->forUser($user->id)->find($projectId);

        if ($project === null) {
            $this->dispatch('toast', type: 'error', message: __('Project not found.'));

            return false;
        }

        if ((int) $project->user_id !== (int) $user->id) {
            $this->dispatch('toast', type: 'error', message: __('Only the owner can restore this project.'));

            return false;
        }

        $this->authorize('restore', $project);

        try {
            $restored = $this->restoreProjectAction->execute($project, $user);
        } catch (\Throwable $e) {
            Log::error('Failed to restore project.', [
                'user_id' => $user->id,
                'project_id' => $projectId,
                'exception' => $e,
            ]);

            $this->dispatch('toast', type: 'error', message: __('Couldn’t restore the project. Try again.'));

            return false;
        }

        if (! $restored) {
            $this->dispatch('toast', type: 'error', message: __('Couldn’t restore the project. Try again.'));

            return false;
        }

        $this->dispatch('toast', type: 'success', message: __('Restored the project.'));

        return true;
    }

    /**
     * @internal Force-delete moved to HandlesTrash.
     */
    private function _removedForceDeleteProject(int $projectId): bool
    {
        $user = $this->requireAuth(__('You must be logged in to permanently delete projects.'));
        if ($user === null) {
            return false;
        }

        $project = Project::query()->withTrashed()->forUser($user->id)->find($projectId);

        if ($project === null) {
            $this->dispatch('toast', type: 'error', message: __('Project not found.'));

            return false;
        }

        if ((int) $project->user_id !== (int) $user->id) {
            $this->dispatch('toast', type: 'error', message: __('Only the owner can permanently delete this project.'));

            return false;
        }

        $this->authorize('forceDelete', $project);

        try {
            $deleted = $this->forceDeleteProjectAction->execute($project, $user);
        } catch (\Throwable $e) {
            Log::error('Failed to permanently delete project.', [
                'user_id' => $user->id,
                'project_id' => $projectId,
                'exception' => $e,
            ]);

            $this->dispatch('toast', type: 'error', message: __('Couldn’t permanently delete the project. Try again.'));

            return false;
        }

        if (! $deleted) {
            $this->dispatch('toast', type: 'error', message: __('Couldn’t permanently delete the project. Try again.'));

            return false;
        }

        $this->dispatch('toast', type: 'success', message: __('Permanently deleted the project.'));

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
     * When "search all items" is active, returns projects across all dates (no date scope).
     */
    #[Computed]
    public function projects(): Collection
    {
        // Early return: Skip if filtered to other item types (before any work)
        $filterItemType = property_exists($this, 'filterItemType') ? $this->normalizeFilterValue($this->filterItemType) : null;
        if ($filterItemType !== null && $filterItemType !== 'projects') {
            return collect();
        }

        $userId = Auth::id();

        if ($userId === null) {
            return collect();
        }

        $projectsPerPage = property_exists($this, 'projectsPerPage') ? (int) $this->projectsPerPage : 10;
        $projectsPage = property_exists($this, 'projectsPage') ? max(1, (int) $this->projectsPage) : 1;
        $visibleLimit = $projectsPerPage * $projectsPage;
        $queryLimit = $visibleLimit + 1;

        $searchAllItems = method_exists($this, 'shouldSearchAllItems') && $this->shouldSearchAllItems();

        $query = Project::query()
            ->with([
                'tasks',
                'user',
                'collaborations',
                'collaborators',
                'collaborationInvitations.invitee',
            ])
            ->withRecentComments(5)
            ->withCount('comments')
            ->withCount('tasks')
            ->withCount('activityLogs')
            ->withRecentActivityLogs(5)
            ->forUser($userId)
            ->notArchived();

        if (! $searchAllItems) {
            $date = method_exists($this, 'getParsedSelectedDate')
                ? $this->getParsedSelectedDate()
                : Carbon::parse($this->selectedDate);
            $query->activeForDate($date);
        }

        $query->orderByDesc('created_at');

        if (method_exists($this, 'applySearchToQuery')) {
            $this->applySearchToQuery($query, 'name');
        }

        $projects = $query
            ->limit($queryLimit)
            ->get();

        $this->hasMoreProjects = $projects->count() > $visibleLimit;

        return $projects->take($visibleLimit);
    }

    /**
     * Load projects for parent selection (e.g. "Put task in project" popover).
     * No date filter; returns all non-archived projects for the user.
     *
     * @return array{items: array<int, array{id: int, name: string}>, hasMore: bool}
     */
    public function loadProjectsForParentSelection(?int $cursorId = null, int $limit = 50): array
    {
        $userId = Auth::id();
        if ($userId === null) {
            return ['items' => [], 'hasMore' => false];
        }

        $query = Project::query()
            ->forUser($userId)
            ->notArchived()
            ->orderBy('name')
            ->limit($limit + 1);

        if ($cursorId !== null) {
            $query->where('id', '>', $cursorId);
        }

        $projects = $query->get(['id', 'name']);
        $hasMore = $projects->count() > $limit;
        $items = $projects->take($limit)->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name])->values()->all();

        return ['items' => $items, 'hasMore' => $hasMore];
    }
}
