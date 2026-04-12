<?php

namespace App\Livewire\Concerns;

use App\DataTransferObjects\Task\CreateTaskDto;
use App\DataTransferObjects\Task\CreateTaskExceptionDto;
use App\Models\Event;
use App\Models\Project;
use App\Models\RecurringTask;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskException;
use App\Models\User;
use App\Support\Validation\TaskExceptionPayloadValidation;
use App\Support\Validation\TaskPayloadValidation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
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
     * Pagination settings for workspace task list.
     */
    public int $tasksPerPage = 10;

    public int $tasksPage = 1;

    public bool $hasMoreTasks = false;

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

        $eventId = $validatedTask['eventId'] ?? null;
        if ($eventId !== null) {
            $event = Event::query()->forUser($user->id)->find((int) $eventId);
            if ($event === null) {
                $this->dispatch('toast', type: 'error', message: __('Event not found.'));

                return;
            }
            $this->authorize('update', $event);
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

        $this->dispatch('task-created', id: $task->id, title: $task->title);
        $this->dispatch('toast', ...Task::toastPayload('create', true, $task->title));

        if (method_exists($this, 'refreshWorkspaceItems')) {
            $this->refreshWorkspaceItems();
        }
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
            $deleteResult = $this->deleteTaskAction->executeWithFocusMeta($task, $user);
        } catch (\Throwable $e) {
            Log::error('Failed to delete task from workspace.', [
                'user_id' => $user->id,
                'task_id' => $taskId,
                'exception' => $e,
            ]);

            $this->dispatch('toast', ...Task::toastPayload('delete', false, $task->title));

            return false;
        }

        if (! $deleteResult['success']) {
            $this->dispatch('toast', ...Task::toastPayload('delete', false, $task->title));

            return false;
        }

        $this->dispatch('toast', ...Task::toastPayload('delete', true, $task->title));

        if (method_exists($this, 'syncActiveFocusSessionFromDatabase')) {
            $this->syncActiveFocusSessionFromDatabase();
        }

        if ($deleteResult['abandoned_in_progress_focus_session']) {
            $this->dispatch('toast', type: 'info', message: __('Focus stopped because the task was moved to trash.'));
        }

        return true;
    }

    /**
     * Update a single task property for the authenticated user (inline editing).
     *
     * @param  bool  $silentToasts  When true, do not dispatch success toast (e.g. when syncing tagIds after delete so only "Tag deleted." is shown).
     */
    #[Async]
    #[Renderless]
    public function updateTaskProperty(int $taskId, string $property, mixed $value, bool $silentToasts = false, ?string $occurrenceDate = null): bool|array
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

        if ($property === 'projectId' && $validatedValue !== null) {
            $project = Project::query()->forUser($user->id)->find((int) $validatedValue);
            if ($project === null) {
                $this->dispatch('toast', type: 'error', message: __('Project not found.'));

                return false;
            }
            $this->authorize('update', $project);
        }

        if ($property === 'eventId' && $validatedValue !== null) {
            $event = Event::query()->forUser($user->id)->find((int) $validatedValue);
            if ($event === null) {
                $this->dispatch('toast', type: 'error', message: __('Event not found.'));

                return false;
            }
            $this->authorize('update', $event);
        }

        if ($property === 'tagIds') {
            Log::info('[TAG-SYNC] Validation passed, calling action', [
                'task_id' => $taskId,
                'validated_value' => $validatedValue,
            ]);
        }

        $result = $this->updateTaskPropertyAction->execute($task, $property, $validatedValue, $occurrenceDate, $user);

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

            if ($property === 'status' && $result->clearedFocusProgress) {
                $this->dispatch('toast', type: 'info', message: __('Logged focus time was cleared so you can start fresh. Restoring a task from trash does not clear it.'));
            }
        }

        if ($property === 'recurrence') {
            $task->load('recurringTask');

            return ['success' => true, 'recurringTaskId' => $task->recurringTask?->id];
        }

        return true;
    }

    /**
     * Get tasks for the selected date for the authenticated user.
     * Uses batch recurrence expansion to avoid N+1 queries.
     * When "search all items" is active, returns tasks across all dates (no date scope).
     */
    #[Computed]
    public function tasks(): Collection
    {
        // Early return: Skip if filtered to other item types (before any work)
        $filterItemType = property_exists($this, 'filterItemType') ? $this->normalizeFilterValue($this->filterItemType) : null;
        if ($filterItemType !== null && $filterItemType !== 'tasks') {
            return collect();
        }

        $userId = Auth::id();

        if ($userId === null) {
            return collect();
        }

        $tasksPerPage = property_exists($this, 'tasksPerPage') ? (int) $this->tasksPerPage : 10;
        $tasksPage = property_exists($this, 'tasksPage') ? max(1, (int) $this->tasksPage) : 1;
        $visibleLimit = $tasksPerPage * $tasksPage;
        $queryLimit = $visibleLimit + 1;

        $searchAllItems = method_exists($this, 'shouldSearchAllItems') && $this->shouldSearchAllItems();

        $taskQuery = Task::query()
            ->with([
                'project',
                'event',
                'user',
                'recurringTask',
                'latestUnfinishedFocusSession',
                'focusSessions' => fn ($query) => $query
                    ->work()
                    ->select(['id', 'focusable_type', 'focusable_id', 'type', 'started_at', 'ended_at', 'paused_at', 'paused_seconds']),
                'tags',
                'collaborations',
                'collaborators',
                'collaborationInvitations.invitee',
            ])
            ->withRecentComments(5)
            ->withCount('comments')
            ->withCount('activityLogs')
            ->withRecentActivityLogs(5)
            ->forUser($userId);

        if (! $searchAllItems) {
            $date = method_exists($this, 'getParsedSelectedDate')
                ? $this->getParsedSelectedDate()
                : Carbon::parse($this->selectedDate);
            $focusTaskId = (int) ($this->focusTaskId ?? 0);

            $taskQuery->where(function (Builder $outer) use ($date, $focusTaskId): void {
                $outer->where(function (Builder $inner) use ($date): void {
                    $inner->relevantForDate($date);
                    if ($date->isToday()) {
                        $inner->where(function (Builder $q): void {
                            $q->whereNull('end_datetime')->orWhere('end_datetime', '>=', now());
                        });
                    }
                });
                if ($focusTaskId > 0) {
                    $outer->orWhere('tasks.id', $focusTaskId);
                }
            });
        }

        if (property_exists($this, 'listContextProjectId') && $this->listContextProjectId !== null && $this->listContextProjectId !== '') {
            $project = Project::query()->forUser($userId)->find((int) $this->listContextProjectId);
            if ($project !== null) {
                $this->authorize('view', $project);
                $taskQuery->forProject($project);
            }
        } elseif (property_exists($this, 'listContextEventId') && $this->listContextEventId !== null && $this->listContextEventId !== '') {
            $event = Event::query()->forUser($userId)->find((int) $this->listContextEventId);
            if ($event !== null) {
                $this->authorize('view', $event);
                $taskQuery->forEvent($event);
            }
        }

        if (method_exists($this, 'applyTaskFilters')) {
            $this->applyTaskFilters($taskQuery);
        }

        if (method_exists($this, 'applyWorkspaceSearchToTaskQuery')) {
            $this->applyWorkspaceSearchToTaskQuery($taskQuery);
        }

        $tasks = $taskQuery
            ->orderByDesc('created_at')
            ->limit($queryLimit)
            ->get();

        $this->hasMoreTasks = $tasks->count() > $visibleLimit;
        $visibleTasks = $tasks->take($visibleLimit);

        if ($searchAllItems) {
            $result = $visibleTasks;
        } else {
            $date = method_exists($this, 'getParsedSelectedDate')
                ? $this->getParsedSelectedDate()
                : Carbon::parse($this->selectedDate);
            $result = $this->taskService->processRecurringTasksForDate($visibleTasks, $date);
        }

        if (method_exists($this, 'filterTaskCollection')) {
            $result = $this->filterTaskCollection($result);
        }

        return $result;
    }

    /**
     * Skip a recurring task occurrence (create a task exception for the given date).
     * Returns the exception id on success, null on validation/authorization/failure.
     *
     * @param  array<string, mixed>  $payload  Must contain taskExceptionPayload with recurringTaskId, exceptionDate, optional isDeleted, reason, replacementInstanceId
     */
    #[Async]
    #[Renderless]
    public function skipRecurringTaskOccurrence(array $payload): ?int
    {
        $user = $this->requireAuth(__('You must be logged in to skip an occurrence.'));
        if ($user === null) {
            return null;
        }

        $payload = array_replace_recursive(TaskExceptionPayloadValidation::createDefaults(), $payload);
        $validator = Validator::make(['taskExceptionPayload' => $payload], TaskExceptionPayloadValidation::createRules());
        if ($validator->fails()) {
            $this->dispatch('toast', type: 'error', message: $validator->errors()->first() ?: __('Invalid request.'));

            return null;
        }

        $validated = $validator->validated()['taskExceptionPayload'];
        $recurring = RecurringTask::query()->with('task')->find((int) $validated['recurringTaskId']);
        if ($recurring === null || $recurring->task === null) {
            $this->dispatch('toast', type: 'error', message: __('Task not found.'));

            return null;
        }

        $task = Task::query()->forUser($user->id)->find($recurring->task->id);
        if ($task === null) {
            $this->dispatch('toast', type: 'error', message: __('Task not found.'));

            return null;
        }

        $this->authorize('update', $task);

        $dto = CreateTaskExceptionDto::fromValidated($validated);

        try {
            $exception = $this->createTaskExceptionAction->execute($user, $dto);
        } catch (\Throwable $e) {
            Log::error('Failed to skip recurring task occurrence.', [
                'user_id' => $user->id,
                'recurring_task_id' => $recurring->id,
                'exception' => $e,
            ]);
            $this->dispatch('toast', type: 'error', message: __('Could not skip occurrence. Please try again.'));

            return null;
        }

        $this->dispatch('recurring-task-occurrence-skipped', taskExceptionId: $exception->id, taskId: $task->id);
        $this->dispatch('toast', type: 'success', message: __('Occurrence skipped.'));

        return $exception->id;
    }

    /**
     * Restore a recurring task occurrence (delete the task exception so the occurrence appears again).
     */
    #[Async]
    #[Renderless]
    public function restoreRecurringTaskOccurrence(int $taskExceptionId): bool
    {
        $user = $this->requireAuth(__('You must be logged in to restore an occurrence.'));
        if ($user === null) {
            return false;
        }

        $exception = TaskException::query()->with('recurringTask.task')->find($taskExceptionId);
        if ($exception === null) {
            $this->dispatch('toast', type: 'error', message: __('Exception not found.'));

            return false;
        }

        $this->authorize('delete', $exception);

        try {
            $deleted = $this->deleteTaskExceptionAction->execute($exception);
        } catch (\Throwable $e) {
            Log::error('Failed to restore recurring task occurrence.', [
                'user_id' => $user->id,
                'task_exception_id' => $taskExceptionId,
                'exception' => $e,
            ]);
            $this->dispatch('toast', type: 'error', message: __('Could not restore occurrence. Please try again.'));

            return false;
        }

        if (! $deleted) {
            $this->dispatch('toast', type: 'error', message: __('Could not restore occurrence. Please try again.'));

            return false;
        }

        $taskId = $exception->recurringTask?->task_id;
        $this->dispatch('recurring-task-occurrence-restored', taskExceptionId: $taskExceptionId, taskId: $taskId);
        $this->dispatch('toast', type: 'success', message: __('Occurrence restored.'));

        return true;
    }

    /**
     * Get task exceptions (skipped dates) for a recurring task. For use in "Skipped dates" / Restore UI.
     *
     * @return array<int, array{id: int, exception_date: string, reason: string|null}>
     */
    public function getTaskExceptions(int $recurringTaskId): array
    {
        $user = $this->requireAuth(__('You must be logged in to view exceptions.'));
        if ($user === null) {
            return [];
        }

        $recurring = RecurringTask::query()->with('task')->find($recurringTaskId);
        if ($recurring === null || $recurring->task === null) {
            return [];
        }

        $task = Task::query()->forUser($user->id)->find($recurring->task->id);
        if ($task === null) {
            return [];
        }

        $this->authorize('update', $task);

        return $recurring->taskExceptions()
            ->orderBy('exception_date', 'desc')
            ->get()
            ->map(fn (TaskException $ex) => [
                'id' => $ex->id,
                'exception_date' => $ex->exception_date->format('Y-m-d'),
                'reason' => $ex->reason,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return TaskPayloadValidation::rules();
    }
}
