<?php

namespace App\Livewire\Concerns;

use App\DataTransferObjects\Event\CreateEventDto;
use App\DataTransferObjects\Task\CreateTaskDto;
use App\Models\Event;
use App\Models\EventInstance;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\User;
use App\Support\DateHelper;
use App\Support\Validation\EventPayloadValidation;
use App\Support\Validation\ProjectPayloadValidation;
use App\Support\Validation\TaskPayloadValidation;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Async;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Renderless;

trait HandlesWorkspaceItems
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
     * Create a new event for the authenticated user.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createEvent(array $payload): void
    {
        $user = $this->requireAuth(__('You must be logged in to create events.'));
        if ($user === null) {
            return;
        }

        $this->authorize('create', Event::class);

        $this->eventPayload = array_replace_recursive(EventPayloadValidation::defaults(), $payload);
        $this->eventPayload['tagIds'] = Tag::validIdsForUser($user->id, $this->eventPayload['tagIds'] ?? []);

        try {
            /** @var array{eventPayload: array<string, mixed>} $validated */
            $validated = $this->validate(EventPayloadValidation::rules());
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Event validation failed', [
                'errors' => $e->errors(),
                'payload' => $this->eventPayload,
            ]);
            $this->dispatch('toast', type: 'error', message: __('Please fix the event details and try again.'));

            return;
        }

        $validatedEvent = $validated['eventPayload'];

        if (($validatedEvent['pendingTagNames'] ?? []) !== []) {
            $this->authorize('create', Tag::class);
        }
        $tagIds = $this->tagService->resolveTagIdsFromPayload($user, $validatedEvent, 'event');
        $validatedEvent['tagIds'] = $tagIds;

        $dto = CreateEventDto::fromValidated($validatedEvent);

        try {
            $event = $this->createEventAction->execute($user, $dto);
        } catch (\Throwable $e) {
            Log::error('Failed to create event from workspace.', [
                'user_id' => $user->id,
                'payload' => $this->eventPayload,
                'exception' => $e,
            ]);

            $this->dispatch('toast', ...Event::toastPayload('create', false, $dto->title));

            return;
        }

        $this->listRefresh++;
        $this->dispatch('event-created', id: $event->id, title: $event->title);
        $this->dispatch('toast', ...Event::toastPayload('create', true, $event->title));
    }

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

        $name = (string) ($validatedProject['name'] ?? '');
        $startDatetime = DateHelper::parseOptional($validatedProject['startDatetime'] ?? null);
        $endDatetime = DateHelper::parseOptional($validatedProject['endDatetime'] ?? null);

        $projectAttributes = [
            'name' => $name,
            'description' => $validatedProject['description'] ?? null,
            'start_datetime' => $startDatetime,
            'end_datetime' => $endDatetime,
        ];

        try {
            $project = $this->projectService->createProject($user, $projectAttributes);
        } catch (\Throwable $e) {
            Log::error('Failed to create project from workspace.', [
                'user_id' => $user->id,
                'payload' => $this->projectPayload,
                'exception' => $e,
            ]);

            $this->dispatch('toast', ...Project::toastPayload('create', false, $name));

            return;
        }

        $this->listRefresh++;
        $this->dispatch('project-created', id: $project->id, name: $project->name);
        $this->dispatch('toast', ...Project::toastPayload('create', true, $project->name));
    }

    /**
     * Create a new tag for the authenticated user.
     *
     * @param  bool  $silentToasts  When true, do not dispatch success/info toasts (e.g. when creating from list-item-card so only "Task updated." is shown).
     */
    #[Async]
    #[Renderless]
    public function createTag(string $name, bool $silentToasts = false): void
    {
        $user = $this->requireAuth(__('You must be logged in to create tags.'));
        if ($user === null) {
            return;
        }

        $this->authorize('create', Tag::class);

        $name = trim($name);

        $validator = Validator::make(
            ['name' => $name],
            [
                'name' => ['required', 'string', 'max:255', 'regex:/\S/'],
            ],
            [
                'name.required' => __('Tag name is required.'),
                'name.max' => __('Tag name cannot exceed 255 characters.'),
                'name.regex' => __('Tag name cannot be empty.'),
            ]
        );

        if ($validator->fails()) {
            Log::error('Tag validation failed', [
                'errors' => $validator->errors()->all(),
                'name' => $name,
            ]);
            $this->dispatch('toast', type: 'error', message: $validator->errors()->first('name') ?: __('Please fix the tag name and try again.'));

            return;
        }

        $validatedName = $validator->validated()['name'];

        try {
            $existingTag = Tag::query()->forUser($user->id)->byName($validatedName)->first();

            if ($existingTag !== null) {
                $this->dispatch('tag-created', id: $existingTag->id, name: $existingTag->name);

                return;
            }

            $tag = $this->tagService->createTag($user, [
                'name' => $validatedName,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to create tag from workspace.', [
                'user_id' => $user->id,
                'name' => $name,
                'exception' => $e,
            ]);

            $this->dispatch('toast', type: 'error', message: __('Something went wrong creating the tag.'));

            return;
        }

        $this->dispatch('tag-created', id: $tag->id, name: $tag->name);
        if (! $silentToasts) {
            $this->dispatch('toast', type: 'success', message: __('Tag created.'));
        }
        $this->dispatch('$refresh');
    }

    /**
     * Delete a tag for the authenticated user.
     *
     * @param  bool  $silentToasts  When true, do not dispatch success toast (e.g. when deleting from list-item-card so only "Task updated." is shown).
     */
    #[Async]
    #[Renderless]
    public function deleteTag(int $tagId, bool $silentToasts = false): void
    {
        $user = $this->requireAuth(__('You must be logged in to delete tags.'));
        if ($user === null) {
            return;
        }

        $tag = Tag::query()->forUser($user->id)->find($tagId);

        if ($tag === null) {
            if (! $silentToasts) {
                $this->dispatch('toast', type: 'error', message: __('Tag not found.'));
            }

            return;
        }

        $this->authorize('delete', $tag);

        try {
            $deleted = $this->tagService->deleteTag($tag);
        } catch (\Throwable $e) {
            Log::error('Failed to delete tag from workspace.', [
                'user_id' => $user->id,
                'tag_id' => $tagId,
                'exception' => $e,
            ]);

            $this->dispatch('toast', type: 'error', message: __('Something went wrong deleting the tag.'));

            return;
        }

        if (! $deleted) {
            $this->dispatch('toast', type: 'error', message: __('Something went wrong deleting the tag.'));

            return;
        }

        $this->dispatch('tag-deleted', id: $tagId);
        if (! $silentToasts) {
            $this->dispatch('toast', type: 'success', message: __('Tag ":name" deleted.', ['name' => $tag->name]));
        }
        $this->dispatch('$refresh');
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

        $this->authorize('delete', $task);

        try {
            $deleted = $this->deleteTaskAction->execute($task);
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
        $user = $this->requireAuth(__('You must be logged in to update tasks.'));
        if ($user === null) {
            return false;
        }

        $task = Task::query()->forUser($user->id)->with('recurringTask')->find($taskId);

        if ($task === null) {
            $this->dispatch('toast', type: 'error', message: __('Task not found.'));

            return false;
        }

        $this->authorize('update', $task);

        if (! in_array($property, TaskPayloadValidation::allowedUpdateProperties(), true)) {
            $this->dispatch('toast', type: 'error', message: __('Invalid property for update.'));

            return false;
        }

        $rules = TaskPayloadValidation::rulesForProperty($property);
        if ($rules === []) {
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

        $validator = Validator::make(['value' => $value], $rules);
        if ($validator->fails()) {
            $this->dispatch('toast', type: 'error', message: $validator->errors()->first('value') ?: __('Invalid value.'));

            return false;
        }

        $validatedValue = $validator->validated()['value'];

        $result = $this->updateTaskPropertyAction->execute($task, $property, $validatedValue, $occurrenceDate);

        if (! $result->success) {
            if ($result->errorMessage !== null) {
                $this->dispatch('toast', type: 'error', message: $result->errorMessage);
            } else {
                $this->dispatch('toast', ...Task::toastPayloadForPropertyUpdate($property, $result->oldValue, $result->newValue, false, $task->title));
            }

            return false;
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

        $this->authorize('delete', $project);

        try {
            $deleted = $this->projectService->deleteProject($project);
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
     * Delete an event for the authenticated user.
     */
    #[Async]
    #[Renderless]
    public function deleteEvent(int $eventId): bool
    {
        $user = $this->requireAuth(__('You must be logged in to delete events.'));
        if ($user === null) {
            return false;
        }

        $event = Event::query()->forUser($user->id)->find($eventId);

        if ($event === null) {
            $this->dispatch('toast', type: 'error', message: __('Event not found.'));

            return false;
        }

        $this->authorize('delete', $event);

        try {
            $deleted = $this->deleteEventAction->execute($event);
        } catch (\Throwable $e) {
            Log::error('Failed to delete event from workspace.', [
                'user_id' => $user->id,
                'event_id' => $eventId,
                'exception' => $e,
            ]);

            $this->dispatch('toast', ...Event::toastPayload('delete', false, $event->title));

            return false;
        }

        if (! $deleted) {
            $this->dispatch('toast', ...Event::toastPayload('delete', false, $event->title));

            return false;
        }

        $this->dispatch('toast', ...Event::toastPayload('delete', true, $event->title));

        return true;
    }

    /**
     * Update a single event property for the authenticated user (inline editing).
     *
     * @param  bool  $silentToasts  When true, do not dispatch success toast (e.g. when syncing tagIds after delete so only "Tag deleted." is shown).
     */
    #[Async]
    #[Renderless]
    public function updateEventProperty(int $eventId, string $property, mixed $value, bool $silentToasts = false, ?string $occurrenceDate = null): bool
    {
        $user = $this->requireAuth(__('You must be logged in to update events.'));
        if ($user === null) {
            return false;
        }

        $event = Event::query()->forUser($user->id)->with('recurringEvent')->find($eventId);

        if ($event === null) {
            $this->dispatch('toast', type: 'error', message: __('Event not found.'));

            return false;
        }

        $this->authorize('update', $event);

        if (! in_array($property, EventPayloadValidation::allowedUpdateProperties(), true)) {
            $this->dispatch('toast', type: 'error', message: __('Invalid property for update.'));

            return false;
        }

        $rules = EventPayloadValidation::rulesForProperty($property);
        if ($rules === []) {
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

        $validator = Validator::make(['value' => $value], $rules);
        if ($validator->fails()) {
            $this->dispatch('toast', type: 'error', message: $validator->errors()->first('value') ?: __('Invalid value.'));

            return false;
        }

        $validatedValue = $validator->validated()['value'];

        $result = $this->updateEventPropertyAction->execute($event, $property, $validatedValue, $occurrenceDate);

        if (! $result->success) {
            if ($result->errorMessage !== null) {
                $this->dispatch('toast', type: 'error', message: $result->errorMessage);
            } else {
                $this->dispatch('toast', ...Event::toastPayloadForPropertyUpdate($property, $result->oldValue, $result->newValue, false, $event->title));
            }

            return false;
        }

        if (! $silentToasts) {
            $this->dispatch('toast', ...Event::toastPayloadForPropertyUpdate(
                $property,
                $result->oldValue,
                $result->newValue,
                true,
                $event->title,
                $result->addedTagName,
                $result->removedTagName
            ));
        }

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

        if ($property === 'endDatetime' && $validatedValue !== null && $project->start_datetime !== null) {
            $endDatetime = DateHelper::parseOptional($validatedValue);
            if ($endDatetime !== null && $endDatetime->lt($project->start_datetime)) {
                $this->dispatch('toast', type: 'error', message: __('End date must be the same as or after the start date.'));

                return false;
            }
        }

        $column = Project::propertyToColumn($property);
        $oldValue = $project->getPropertyValueForUpdate($property);

        $attributes = [$column => $validatedValue];
        if ($column === 'start_datetime' || $column === 'end_datetime') {
            $attributes[$column] = DateHelper::parseOptional($validatedValue);
        }

        try {
            $this->projectService->updateProject($project, $attributes);
        } catch (\Throwable $e) {
            Log::error('Failed to update project property from workspace.', [
                'user_id' => $user->id,
                'project_id' => $projectId,
                'property' => $property,
                'exception' => $e,
            ]);
            $this->dispatch('toast', ...Project::toastPayloadForPropertyUpdate($property, $oldValue, $validatedValue, false, $project->name));

            return false;
        }

        if (! $silentToasts) {
            $newValue = in_array($property, ['startDatetime', 'endDatetime'], true) ? ($attributes[$column] ?? null) : $validatedValue;
            $this->dispatch('toast', ...Project::toastPayloadForPropertyUpdate($property, $oldValue, $newValue, true, $project->name));
        }

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return TaskPayloadValidation::rules();
    }

    private function requireAuth(string $message): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            $this->dispatch('toast', type: 'error', message: $message);

            return null;
        }

        return $user;
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

        $date = Carbon::parse($this->selectedDate);

        $taskQuery = Task::query()
            ->with(['project', 'event', 'recurringTask', 'tags', 'collaborations'])
            ->forUser($userId)
            ->incomplete()
            ->relevantForDate($date);

        if (method_exists($this, 'applyTaskFilters')) {
            $this->applyTaskFilters($taskQuery);
        }

        $tasks = $taskQuery->orderByDesc('created_at')->limit(50)->get();

        $recurringTasks = $tasks->pluck('recurringTask')->filter();
        $relevantIds = $recurringTasks->isNotEmpty()
            ? $this->recurrenceExpander->getRelevantRecurringIdsForDate($recurringTasks, collect(), $date)
            : ['task_ids' => [], 'event_ids' => []];

        $relevantTaskIds = array_flip($relevantIds['task_ids']);

        $filteredTasks = $tasks
            ->filter(function (Task $task) use ($relevantTaskIds): bool {
                if ($task->recurringTask === null) {
                    return true;
                }

                return isset($relevantTaskIds[$task->recurringTask->id]);
            })
            ->values();

        $recurringTaskIds = $filteredTasks->pluck('recurringTask.id')->filter()->values();
        $instancesByRecurringId = $recurringTaskIds->isNotEmpty()
            ? TaskInstance::query()
                ->whereIn('recurring_task_id', $recurringTaskIds)
                ->whereDate('instance_date', $date)
                ->get()
                ->keyBy('recurring_task_id')
            : collect();

        $result = $filteredTasks
            ->map(function (Task $task) use ($date, $instancesByRecurringId): Task {
                if ($task->recurringTask !== null) {
                    $task->instanceForDate = $instancesByRecurringId->get($task->recurringTask->id);
                }
                $task->effectiveStatusForDate = $this->taskService->getEffectiveStatusForDate($task, $date);

                return $task;
            })
            ->values();

        if (method_exists($this, 'filterTaskCollection')) {
            $result = $this->filterTaskCollection($result);
        }

        return $result;
    }

    /**
     * Get overdue tasks and events for the authenticated user.
     * Overdue = end/due date is before today (not the selected view date).
     * Returns a unified collection of entries with 'kind' and 'item' for rendering.
     */
    #[Computed]
    public function overdue(): Collection
    {
        $userId = Auth::id();

        if ($userId === null) {
            return collect();
        }

        $today = Carbon::today();

        $overdueTaskQuery = Task::query()
            ->with(['project', 'tags', 'collaborations'])
            ->forUser($userId)
            ->incomplete()
            ->overdue($today)
            ->whereDoesntHave('recurringTask');

        if (method_exists($this, 'applyOverdueTaskFilters')) {
            $this->applyOverdueTaskFilters($overdueTaskQuery);
        }

        $overdueTasks = $overdueTaskQuery->orderByPriority()->limit(50)->get()
            ->map(fn (Task $task) => ['kind' => 'task', 'item' => $task]);

        $overdueEventQuery = Event::query()
            ->with(['tags', 'collaborations'])
            ->forUser($userId)
            ->notCancelled()
            ->notCompleted()
            ->overdue($today)
            ->whereDoesntHave('recurringEvent');

        if (method_exists($this, 'applyOverdueEventFilters')) {
            $this->applyOverdueEventFilters($overdueEventQuery);
        }

        $overdueEvents = $overdueEventQuery->orderBy('end_datetime')->limit(50)->get()
            ->map(fn (Event $event) => ['kind' => 'event', 'item' => $event]);

        return collect($overdueTasks)
            ->merge($overdueEvents)
            ->sortBy(fn (array $entry) => $entry['item']->end_datetime?->timestamp ?? 0)
            ->values();
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

        $date = Carbon::parse($this->selectedDate);

        return Project::query()
            ->with([
                'tasks',
                'collaborations',
            ])
            ->forUser($userId)
            ->notArchived()
            ->activeForDate($date)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
    }

    /**
     * Get events for the selected date for the authenticated user.
     * Uses batch recurrence expansion to avoid N+1 queries.
     */
    #[Computed]
    public function events(): Collection
    {
        $userId = Auth::id();

        if ($userId === null) {
            return collect();
        }

        $date = Carbon::parse($this->selectedDate);

        $eventQuery = Event::query()
            ->with(['recurringEvent', 'tags', 'collaborations'])
            ->forUser($userId)
            ->activeForDate($date);

        if (method_exists($this, 'applyEventFilters')) {
            $this->applyEventFilters($eventQuery);
        } else {
            $eventQuery->notCancelled()->notCompleted();
        }

        $events = $eventQuery->orderByDesc('created_at')->limit(50)->get();

        $recurringEvents = $events->pluck('recurringEvent')->filter();
        $relevantIds = $recurringEvents->isNotEmpty()
            ? $this->recurrenceExpander->getRelevantRecurringIdsForDate(collect(), $recurringEvents, $date)
            : ['task_ids' => [], 'event_ids' => []];

        $relevantEventIds = array_flip($relevantIds['event_ids']);

        $filteredEvents = $events
            ->filter(function (Event $event) use ($relevantEventIds): bool {
                if ($event->recurringEvent === null) {
                    return true;
                }

                return isset($relevantEventIds[$event->recurringEvent->id]);
            })
            ->values();

        $recurringEventIds = $filteredEvents->pluck('recurringEvent.id')->filter()->values();
        $instancesByRecurringId = $recurringEventIds->isNotEmpty()
            ? EventInstance::query()
                ->whereIn('recurring_event_id', $recurringEventIds)
                ->whereDate('instance_date', $date)
                ->get()
                ->keyBy('recurring_event_id')
            : collect();

        $result = $filteredEvents
            ->map(function (Event $event) use ($date, $instancesByRecurringId): Event {
                if ($event->recurringEvent !== null) {
                    $event->instanceForDate = $instancesByRecurringId->get($event->recurringEvent->id);
                }
                $event->effectiveStatusForDate = $this->eventService->getEffectiveStatusForDate($event, $date);

                return $event;
            })
            ->values();

        if (method_exists($this, 'filterEventCollection')) {
            $result = $this->filterEventCollection($result);
        }

        return $result;
    }

    /**
     * Get tags for the authenticated user.
     */
    #[Computed]
    public function tags(): Collection
    {
        $userId = Auth::id();

        if ($userId === null) {
            return collect();
        }

        return Tag::query()
            ->forUser($userId)
            ->orderBy('name')
            ->get();
    }
}
