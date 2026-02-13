<?php

namespace App\Livewire\Concerns;

use App\DataTransferObjects\Task\CreateTaskDto;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Support\Validation\TaskPayloadValidation;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Async;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Renderless;

trait HandlesTasks
{
    /**
     * Create a new task for the authenticated user.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createTask(array $payload): void
    {
        $user = $this->requireAuth(__('You must be logged in to create tasks.'));
        if ($user === null) {
            return;
        }

        $this->authorize('create', Task::class);

        $this->taskPayload = array_replace_recursive(TaskPayloadValidation::defaults(), $payload);
        $this->taskPayload['tagIds'] = Tag::validIdsForUser($user->id, $this->taskPayload['tagIds'] ?? []);

        try {
            /** @var array{taskPayload: array<string, mixed>} $validated */
            $validated = $this->validate(TaskPayloadValidation::rules());
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Task validation failed', [
                'errors' => $e->errors(),
                'payload' => $this->taskPayload,
            ]);
            $this->dispatch('toast', type: 'error', message: __('Please fix the task details and try again.'));

            return;
        }

        $validatedTask = $validated['taskPayload'];

        $projectId = $validatedTask['projectId'] ?? null;
        if ($projectId !== null) {
            $project = Project::query()->forUser($user->id)->find((int) $projectId);
            if ($project === null) {
                $this->dispatch('toast', type: 'error', message: __('Project not found.'));

                return;
            }
            $this->authorize('update', $project);
        }

        if (($validatedTask['pendingTagNames'] ?? []) !== []) {
            $this->authorize('create', Tag::class);
        }
        $tagIds = $this->tagService->resolveTagIdsFromPayload($user, $validatedTask, 'task');
        $validatedTask['tagIds'] = $tagIds;

        $dto = CreateTaskDto::fromValidated($validatedTask);

        try {
            $task = $this->createTaskAction->execute($user, $dto);
        } catch (\Throwable $e) {
            Log::error('Failed to create task from workspace.', [
                'user_id' => $user->id,
                'payload' => $this->taskPayload,
                'exception' => $e,
            ]);

            $this->dispatch('toast', ...Task::toastPayload('create', false, $dto->title));

            return;
        }

        $this->listRefresh++;
        $this->dispatch('task-created', id: $task->id, title: $task->title);
        $this->dispatch('toast', ...Task::toastPayload('create', true, $task->title));
    }

    /**
     * Delete a task for the authenticated user.
     */
    #[Async]
    #[Renderless]
    public function deleteTask(int $taskId): bool
    {
        $user = $this->requireAuth(__('You must be logged in to delete tasks.'));
        if ($user === null) {
            return false;
        }

        $task = Task::query()->forUser($user->id)->find($taskId);

        if ($task === null) {
            $this->dispatch('toast', type: 'error', message: __('Task not found.'));

            return false;
        }

        if ((int) $task->user_id !== (int) $user->id) {
            $this->dispatch('toast', type: 'error', message: __('Only the owner can delete this task.'));

            return false;
        }

        $this->authorize('delete', $task);

        try {
            $deleted = $this->deleteTaskAction->execute($task, $user);
        } catch (\Throwable $e) {
            Log::error('Failed to delete task from workspace.', [
                'user_id' => $user->id,
                'task_id' => $taskId,
                'exception' => $e,
            ]);

            $this->dispatch('toast', ...Task::toastPayload('delete', false, $task->title));

            return false;
        }

        if (! $deleted) {
            $this->dispatch('toast', ...Task::toastPayload('delete', false, $task->title));

            return false;
        }

        $this->dispatch('toast', ...Task::toastPayload('delete', true, $task->title));

        return true;
    }

    /**
     * Update a single task property for the authenticated user (inline editing).
     *
     * @param  bool  $silentToasts  When true, do not dispatch success toast (e.g. when syncing tagIds after delete so only "Tag deleted." is shown).
     */
    #[Async]
    #[Renderless]
    public function updateTaskProperty(int $taskId, string $property, mixed $value, bool $silentToasts = false, ?string $occurrenceDate = null): bool
    {
        if ($property === 'tagIds') {
            Log::info('[TAG-SYNC] Livewire received updateTaskProperty call', [
                'task_id' => $taskId,
                'property' => $property,
                'value' => $value,
                'value_type' => gettype($value),
                'value_is_array' => is_array($value),
                'value_count' => is_array($value) ? count($value) : null,
                'silent_toasts' => $silentToasts,
                'user_id' => Auth::id(),
            ]);
        }

        $user = $this->requireAuth(__('You must be logged in to update tasks.'));
        if ($user === null) {
            if ($property === 'tagIds') {
                Log::warning('[TAG-SYNC] Task update failed - no authenticated user', [
                    'task_id' => $taskId,
                    'property' => $property,
                ]);
            }

            return false;
        }

        $task = Task::query()->forUser($user->id)->with('recurringTask')->withRecentActivityLogs(5)->find($taskId);

        if ($task === null) {
            if ($property === 'tagIds') {
                Log::warning('[TAG-SYNC] Task update failed - task not found', [
                    'task_id' => $taskId,
                    'property' => $property,
                    'user_id' => $user->id,
                ]);
            }

            $this->dispatch('toast', type: 'error', message: __('Task not found.'));

            return false;
        }

        $this->authorize('update', $task);

        // Only the owner can change date/recurrence/tag fields, even if collaborators can edit other properties.
        $isOwner = (int) $task->user_id === (int) $user->id;
        if (! $isOwner && in_array($property, ['startDatetime', 'endDatetime'], true)) {
            $this->dispatch('toast', type: 'error', message: __('Only the owner can change dates for this task.'));

            return false;
        }

        if (! $isOwner && $property === 'recurrence') {
            $this->dispatch('toast', type: 'error', message: __('Only the owner can change repeat for this task.'));

            return false;
        }

        if (! $isOwner && $property === 'tagIds') {
            $this->dispatch('toast', type: 'error', message: __('Only the owner can change tags for this task.'));

            return false;
        }

        if (! in_array($property, TaskPayloadValidation::allowedUpdateProperties(), true)) {
            if ($property === 'tagIds') {
                Log::warning('[TAG-SYNC] Task update failed - invalid property', [
                    'task_id' => $taskId,
                    'property' => $property,
                ]);
            }

            $this->dispatch('toast', type: 'error', message: __('Invalid property for update.'));

            return false;
        }

        $rules = TaskPayloadValidation::rulesForProperty($property);
        if ($rules === []) {
            if ($property === 'tagIds') {
                Log::warning('[TAG-SYNC] Task update failed - no rules for property', [
                    'task_id' => $taskId,
                    'property' => $property,
                ]);
            }

            $this->dispatch('toast', type: 'error', message: __('Invalid property for update.'));

            return false;
        }

        // Explicit validation for title property - reject empty/whitespace-only values before validator
        if ($property === 'title') {
            $trimmedValue = is_string($value) ? trim($value) : $value;
            if (empty($trimmedValue)) {
                $this->dispatch('toast', type: 'error', message: __('Title cannot be empty.'));

                return false;
            }
            $value = $trimmedValue;
        }

        if ($property === 'tagIds') {
            Log::info('[TAG-SYNC] About to validate tagIds', [
                'task_id' => $taskId,
                'value' => $value,
                'rules' => $rules,
            ]);
        }

        $validator = Validator::make(['value' => $value], $rules);
        if ($validator->fails()) {
            if ($property === 'tagIds') {
                Log::error('[TAG-SYNC] Task tagIds validation failed', [
                    'task_id' => $taskId,
                    'value' => $value,
                    'errors' => $validator->errors()->all(),
                ]);
            }

            $this->dispatch('toast', type: 'error', message: $validator->errors()->first('value') ?: __('Invalid value.'));

            return false;
        }

        $validatedValue = $validator->validated()['value'];

        if ($property === 'tagIds') {
            Log::info('[TAG-SYNC] Validation passed, calling action', [
                'task_id' => $taskId,
                'validated_value' => $validatedValue,
            ]);
        }

        $result = $this->updateTaskPropertyAction->execute($task, $property, $validatedValue, $occurrenceDate);

        if ($property === 'tagIds') {
            Log::info('[TAG-SYNC] Action execution completed', [
                'task_id' => $taskId,
                'success' => $result->success,
                'old_value' => $result->oldValue,
                'new_value' => $result->newValue,
                'error_message' => $result->errorMessage,
            ]);
        }

        if (! $result->success) {
            if ($property === 'tagIds') {
                Log::error('[TAG-SYNC] Task tag update failed', [
                    'task_id' => $taskId,
                    'old_value' => $result->oldValue,
                    'new_value' => $result->newValue,
                    'error_message' => $result->errorMessage,
                ]);
            }

            if ($result->errorMessage !== null) {
                $this->dispatch('toast', type: 'error', message: $result->errorMessage);
            } else {
                $this->dispatch('toast', ...Task::toastPayloadForPropertyUpdate($property, $result->oldValue, $result->newValue, false, $task->title));
            }

            return false;
        }

        if ($property === 'tagIds') {
            Log::info('[TAG-SYNC] Task tag update succeeded, dispatching toast', [
                'task_id' => $taskId,
                'silent_toasts' => $silentToasts,
            ]);
        }

        if (! $silentToasts) {
            $this->dispatch('toast', ...Task::toastPayloadForPropertyUpdate(
                $property,
                $result->oldValue,
                $result->newValue,
                true,
                $task->title,
                $result->addedTagName,
                $result->removedTagName
            ));
        }

        return true;
    }

    /**
     * Get tasks for the selected date for the authenticated user.
     * Uses batch recurrence expansion to avoid N+1 queries.
     */
    #[Computed]
    public function tasks(): Collection
    {
        $userId = Auth::id();

        if ($userId === null) {
            return collect();
        }

        $filterItemType = property_exists($this, 'filterItemType') ? $this->normalizeFilterValue($this->filterItemType) : null;
        if ($filterItemType !== null && $filterItemType !== 'tasks') {
            return collect();
        }

        $date = Carbon::parse($this->selectedDate);

        $taskQuery = Task::query()
            ->with([
                'project',
                'event',
                'user',
                'recurringTask',
                'tags',
                'collaborations',
                'collaborators',
                'collaborationInvitations.invitee',
            ])
            ->withRecentComments(5)
            ->withCount('comments')
            ->withCount('activityLogs')
            ->withRecentActivityLogs(5)
            ->forUser($userId)
            ->incomplete()
            ->relevantForDate($date);

        if (method_exists($this, 'applyTaskFilters')) {
            $this->applyTaskFilters($taskQuery);
        }

        $tasks = $taskQuery->orderByDesc('created_at')->limit(50)->get();

        $result = $this->taskService->processRecurringTasksForDate($tasks, $date);

        if (method_exists($this, 'filterTaskCollection')) {
            $result = $this->filterTaskCollection($result);
        }

        return $result;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return TaskPayloadValidation::rules();
    }
}
