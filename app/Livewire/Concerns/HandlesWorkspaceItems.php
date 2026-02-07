<?php

namespace App\Livewire\Concerns;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventInstance;
use App\Models\Project;
use App\Models\RecurringEvent;
use App\Models\RecurringTask;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\User;
use App\Support\Validation\EventPayloadValidation;
use App\Support\Validation\ProjectPayloadValidation;
use App\Support\Validation\TaskPayloadValidation;
use Carbon\Carbon;
use Illuminate\Support\Carbon as SupportCarbon;
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
        $this->taskPayload['tagIds'] = $this->filterValidTagIdsForUser($user->id, $this->taskPayload['tagIds'] ?? []);

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

        $title = (string) ($validatedTask['title'] ?? '');
        $startDatetime = $this->parseOptionalDatetime($validatedTask['startDatetime'] ?? null);
        $endDatetime = $this->parseOptionalDatetime($validatedTask['endDatetime'] ?? null);

        $tagIds = $this->resolveTagIdsFromPayload($user, $validatedTask, 'task');

        $recurrenceData = $validatedTask['recurrence'] ?? null;
        $recurrenceEnabled = $recurrenceData['enabled'] ?? false;

        $taskAttributes = [
            'title' => $title,
            'description' => $validatedTask['description'] ?? null,
            'status' => $validatedTask['status'] ?? null,
            'priority' => $validatedTask['priority'] ?? null,
            'complexity' => $validatedTask['complexity'] ?? null,
            'duration' => $validatedTask['duration'] ?? null,
            'start_datetime' => $startDatetime,
            'end_datetime' => $endDatetime,
            'project_id' => $validatedTask['projectId'] ?? null,
            'tagIds' => $tagIds,
            'recurrence' => $recurrenceEnabled ? $recurrenceData : null,
        ];

        try {
            $task = $this->taskService->createTask($user, $taskAttributes);
        } catch (\Throwable $e) {
            Log::error('Failed to create task from workspace.', [
                'user_id' => $user->id,
                'payload' => $this->taskPayload,
                'exception' => $e,
            ]);

            $this->dispatch('toast', ...Task::toastPayload('create', false, $title));

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
        $this->eventPayload['tagIds'] = $this->filterValidTagIdsForUser($user->id, $this->eventPayload['tagIds'] ?? []);

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

        $title = (string) ($validatedEvent['title'] ?? '');
        $startDatetime = $this->parseOptionalDatetime($validatedEvent['startDatetime'] ?? null);
        $endDatetime = $this->parseOptionalDatetime($validatedEvent['endDatetime'] ?? null);

        $tagIds = $this->resolveTagIdsFromPayload($user, $validatedEvent, 'event');

        $recurrenceData = $validatedEvent['recurrence'] ?? null;
        $recurrenceEnabled = $recurrenceData['enabled'] ?? false;

        $eventAttributes = [
            'title' => $title,
            'description' => $validatedEvent['description'] ?? null,
            'status' => $validatedEvent['status'] ?? null,
            'start_datetime' => $startDatetime,
            'end_datetime' => $endDatetime,
            'all_day' => $validatedEvent['allDay'] ?? false,
            'tagIds' => $tagIds,
            'recurrence' => $recurrenceEnabled ? $recurrenceData : null,
        ];

        try {
            $event = $this->eventService->createEvent($user, $eventAttributes);
        } catch (\Throwable $e) {
            Log::error('Failed to create event from workspace.', [
                'user_id' => $user->id,
                'payload' => $this->eventPayload,
                'exception' => $e,
            ]);

            $this->dispatch('toast', ...Event::toastPayload('create', false, $title));

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
        $startDatetime = $this->parseOptionalDatetime($validatedProject['startDatetime'] ?? null);
        $endDatetime = $this->parseOptionalDatetime($validatedProject['endDatetime'] ?? null);

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
            $deleted = $this->taskService->deleteTask($task);
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

        if ($property === 'tagIds') {
            $oldTagIds = $task->tags()->pluck('tags.id')->all();
            $addedIds = array_values(array_diff($validatedValue, $oldTagIds));
            $removedIds = array_values(array_diff($oldTagIds, $validatedValue));
            $addedTagName = count($addedIds) === 1 ? (Tag::find($addedIds[0])?->name ?? null) : null;
            $removedTagName = count($removedIds) === 1 ? (Tag::find($removedIds[0])?->name ?? null) : null;

            try {
                $task->tags()->sync($validatedValue);
            } catch (\Throwable $e) {
                Log::error('Failed to sync task tags from workspace.', [
                    'user_id' => $user->id,
                    'task_id' => $taskId,
                    'exception' => $e,
                ]);
                $this->dispatch('toast', ...Task::toastPayloadForPropertyUpdate('tagIds', $oldTagIds, $validatedValue, false, $task->title));

                return false;
            }
            if (! $silentToasts) {
                $this->dispatch('toast', ...Task::toastPayloadForPropertyUpdate('tagIds', $oldTagIds, $validatedValue, true, $task->title, $addedTagName, $removedTagName));
            }

            return true;
        }

        if ($property === 'recurrence') {
            $task->loadMissing('recurringTask');
            $oldRecurrence = $this->buildRecurrencePayloadFromModel($task->recurringTask);
            try {
                $this->taskService->updateOrCreateRecurringTask($task, $validatedValue);
            } catch (\Throwable $e) {
                Log::error('Failed to update task recurrence from workspace.', [
                    'user_id' => $user->id,
                    'task_id' => $taskId,
                    'exception' => $e,
                ]);
                $this->dispatch('toast', ...Task::toastPayloadForPropertyUpdate('recurrence', $oldRecurrence, $validatedValue, false, $task->title));

                return false;
            }
            $this->dispatch('toast', ...Task::toastPayloadForPropertyUpdate('recurrence', $oldRecurrence, $validatedValue, true, $task->title));

            return true;
        }

        if ($property === 'status') {
            $recurringTask = $task->recurringTask ?? RecurringTask::where('task_id', $taskId)->first();
            if ($recurringTask !== null) {
                $task->setRelation('recurringTask', $recurringTask);
                try {
                    $oldStatus = $task->status?->value;
                    $statusEnum = \App\Enums\TaskStatus::tryFrom($validatedValue) ?? $task->status;

                    if ($occurrenceDate !== null && $occurrenceDate !== '') {
                        $this->taskService->updateRecurringOccurrenceStatus($task, Carbon::parse($occurrenceDate), $statusEnum);
                    } else {
                        $this->taskService->updateTask($task, ['status' => $validatedValue]);
                    }

                    if (! $silentToasts) {
                        $this->dispatch('toast', ...Task::toastPayloadForPropertyUpdate('status', $oldStatus, $validatedValue, true, $task->title));
                    }

                    return true;
                } catch (\Throwable $e) {
                    Log::error('Failed to update recurring task status from workspace.', [
                        'user_id' => $user->id,
                        'task_id' => $taskId,
                        'exception' => $e,
                    ]);
                    $this->dispatch('toast', ...Task::toastPayloadForPropertyUpdate('status', $task->status?->value, $validatedValue, false, $task->title));

                    return false;
                }
            }
        }

        $column = match ($property) {
            'startDatetime' => 'start_datetime',
            'endDatetime' => 'end_datetime',
            default => $property,
        };

        $oldValue = match ($column) {
            'status' => $task->status?->value,
            'priority' => $task->priority?->value,
            'complexity' => $task->complexity?->value,
            'start_datetime' => $task->start_datetime,
            'end_datetime' => $task->end_datetime,
            default => $task->{$column},
        };

        $attributes = [$column => $validatedValue];
        if ($column === 'start_datetime' || $column === 'end_datetime') {
            $parsedDatetime = $this->parseOptionalDatetime($validatedValue);
            $attributes[$column] = $parsedDatetime;

            $start = $column === 'start_datetime' ? $parsedDatetime : $task->start_datetime;
            $end = $column === 'end_datetime' ? $parsedDatetime : $task->end_datetime;
            $durationMinutes = (int) ($task->duration ?? 0);

            $dateRangeError = TaskPayloadValidation::validateTaskDateRangeForUpdate($start, $end, $durationMinutes);
            if ($dateRangeError !== null) {
                $this->dispatch('toast', type: 'error', message: $dateRangeError);

                return false;
            }
        }

        try {
            $this->taskService->updateTask($task, $attributes);
        } catch (\Throwable $e) {
            Log::error('Failed to update task property from workspace.', [
                'user_id' => $user->id,
                'task_id' => $taskId,
                'property' => $property,
                'exception' => $e,
            ]);
            $this->dispatch('toast', ...Task::toastPayloadForPropertyUpdate($property, $oldValue, $validatedValue, false, $task->title));

            return false;
        }

        if (! $silentToasts) {
            $newValue = in_array($property, ['startDatetime', 'endDatetime'], true) ? ($attributes[$column] ?? null) : $validatedValue;
            $this->dispatch('toast', ...Task::toastPayloadForPropertyUpdate($property, $oldValue, $newValue, true, $task->title));
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
            $deleted = $this->eventService->deleteEvent($event);
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

        if ($property === 'tagIds') {
            $oldTagIds = $event->tags()->pluck('tags.id')->all();
            $addedIds = array_values(array_diff($validatedValue, $oldTagIds));
            $removedIds = array_values(array_diff($oldTagIds, $validatedValue));
            $addedTagName = count($addedIds) === 1 ? (Tag::find($addedIds[0])?->name ?? null) : null;
            $removedTagName = count($removedIds) === 1 ? (Tag::find($removedIds[0])?->name ?? null) : null;

            try {
                $event->tags()->sync($validatedValue);
            } catch (\Throwable $e) {
                Log::error('Failed to sync event tags from workspace.', [
                    'user_id' => $user->id,
                    'event_id' => $eventId,
                    'exception' => $e,
                ]);
                $this->dispatch('toast', ...Event::toastPayloadForPropertyUpdate('tagIds', $oldTagIds, $validatedValue, false, $event->title));

                return false;
            }
            if (! $silentToasts) {
                $this->dispatch('toast', ...Event::toastPayloadForPropertyUpdate('tagIds', $oldTagIds, $validatedValue, true, $event->title, $addedTagName, $removedTagName));
            }

            return true;
        }

        if ($property === 'recurrence') {
            $event->loadMissing('recurringEvent');
            $oldRecurrence = $this->buildRecurrencePayloadFromModel($event->recurringEvent);
            try {
                $this->eventService->updateOrCreateRecurringEvent($event, $validatedValue);
            } catch (\Throwable $e) {
                Log::error('Failed to update event recurrence from workspace.', [
                    'user_id' => $user->id,
                    'event_id' => $eventId,
                    'exception' => $e,
                ]);
                $this->dispatch('toast', ...Event::toastPayloadForPropertyUpdate('recurrence', $oldRecurrence, $validatedValue, false, $event->title));

                return false;
            }
            $this->dispatch('toast', ...Event::toastPayloadForPropertyUpdate('recurrence', $oldRecurrence, $validatedValue, true, $event->title));

            return true;
        }

        if ($property === 'status') {
            $recurringEvent = $event->recurringEvent ?? RecurringEvent::where('event_id', $eventId)->first();
            if ($recurringEvent !== null) {
                $event->setRelation('recurringEvent', $recurringEvent);
                try {
                    $oldStatus = $event->status?->value;
                    $statusEnum = \App\Enums\EventStatus::tryFrom($validatedValue) ?? $event->status;

                    if ($occurrenceDate !== null && $occurrenceDate !== '') {
                        $this->eventService->updateRecurringOccurrenceStatus($event, Carbon::parse($occurrenceDate), $statusEnum);
                    } else {
                        $this->eventService->updateEvent($event, ['status' => $validatedValue]);
                    }

                    if (! $silentToasts) {
                        $this->dispatch('toast', ...Event::toastPayloadForPropertyUpdate('status', $oldStatus, $validatedValue, true, $event->title));
                    }

                    return true;
                } catch (\Throwable $e) {
                    Log::error('Failed to update recurring event status from workspace.', [
                        'user_id' => $user->id,
                        'event_id' => $eventId,
                        'exception' => $e,
                    ]);
                    $this->dispatch('toast', ...Event::toastPayloadForPropertyUpdate('status', $event->status?->value, $validatedValue, false, $event->title));

                    return false;
                }
            }
        }

        $column = match ($property) {
            'startDatetime' => 'start_datetime',
            'endDatetime' => 'end_datetime',
            'allDay' => 'all_day',
            default => $property,
        };

        $oldValue = match ($column) {
            'status' => $event->status?->value,
            'start_datetime' => $event->start_datetime,
            'end_datetime' => $event->end_datetime,
            'all_day' => $event->all_day,
            default => $event->{$column},
        };

        $attributes = [$column => $validatedValue];
        if ($column === 'start_datetime' || $column === 'end_datetime') {
            $parsedDatetime = $this->parseOptionalDatetime($validatedValue);
            $attributes[$column] = $parsedDatetime;

            $start = $column === 'start_datetime' ? $parsedDatetime : $event->start_datetime;
            $end = $column === 'end_datetime' ? $parsedDatetime : $event->end_datetime;

            $dateRangeError = EventPayloadValidation::validateEventDateRangeForUpdate($start, $end);
            if ($dateRangeError !== null) {
                $this->dispatch('toast', type: 'error', message: $dateRangeError);

                return false;
            }
        }

        try {
            $this->eventService->updateEvent($event, $attributes);
        } catch (\Throwable $e) {
            Log::error('Failed to update event property from workspace.', [
                'user_id' => $user->id,
                'event_id' => $eventId,
                'property' => $property,
                'exception' => $e,
            ]);
            $this->dispatch('toast', ...Event::toastPayloadForPropertyUpdate($property, $oldValue, $validatedValue, false, $event->title));

            return false;
        }

        if (! $silentToasts) {
            $newValue = in_array($property, ['startDatetime', 'endDatetime'], true) ? ($attributes[$column] ?? null) : $validatedValue;
            $this->dispatch('toast', ...Event::toastPayloadForPropertyUpdate($property, $oldValue, $newValue, true, $event->title));
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
            $endDatetime = $this->parseOptionalDatetime($validatedValue);
            if ($endDatetime !== null && $endDatetime->lt($project->start_datetime)) {
                $this->dispatch('toast', type: 'error', message: __('End date must be the same as or after the start date.'));

                return false;
            }
        }

        $column = match ($property) {
            'startDatetime' => 'start_datetime',
            'endDatetime' => 'end_datetime',
            default => $property,
        };

        $oldValue = match ($column) {
            'start_datetime' => $project->start_datetime,
            'end_datetime' => $project->end_datetime,
            default => $project->{$column},
        };

        $attributes = [$column => $validatedValue];
        if ($column === 'start_datetime' || $column === 'end_datetime') {
            $attributes[$column] = $this->parseOptionalDatetime($validatedValue);
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
     * Filter tag IDs to only those that exist and belong to the user.
     * Prevents validation errors when the frontend has stale tag IDs (e.g. deleted tags).
     *
     * @param  array<int|string>  $tagIds
     * @return array<int>
     */
    private function filterValidTagIdsForUser(int $userId, array $tagIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $tagIds))));
        if ($ids === []) {
            return [];
        }

        return Tag::query()->forUser($userId)->whereIn('id', $ids)->pluck('id')->all();
    }

    /**
     * Resolve tag IDs from validated payload (tagIds + pendingTagNames).
     *
     * @param  array{tagIds?: int[], pendingTagNames?: string[]}  $validated
     * @return array<int>
     */
    private function resolveTagIdsFromPayload(User $user, array $validated, string $context): array
    {
        $tagIds = array_values(array_unique(array_map('intval', $validated['tagIds'] ?? [])));
        $pendingTagNames = $validated['pendingTagNames'] ?? [];
        if ($pendingTagNames !== []) {
            $this->authorize('create', Tag::class);
        }
        foreach ($pendingTagNames as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $existingTag = Tag::query()->forUser($user->id)->byName($name)->first();
            if ($existingTag !== null) {
                $tagIds[] = $existingTag->id;

                continue;
            }
            try {
                $tag = $this->tagService->createTag($user, ['name' => $name]);
                $tagIds[] = $tag->id;
            } catch (\Throwable $e) {
                Log::error("Failed to create tag when creating {$context}.", [
                    'user_id' => $user->id,
                    'name' => $name,
                    'exception' => $e,
                ]);
            }
        }

        return array_values(array_unique($tagIds));
    }

    /**
     * Build recurrence payload array from RecurringTask or RecurringEvent model.
     *
     * @return array{enabled: bool, type: ?string, interval: int, daysOfWeek: array}
     */
    private function buildRecurrencePayloadFromModel(RecurringTask|RecurringEvent|null $recurring): array
    {
        return $recurring
            ? [
                'enabled' => true,
                'type' => $recurring->recurrence_type?->value,
                'interval' => $recurring->interval ?? 1,
                'daysOfWeek' => $recurring->days_of_week ? (json_decode($recurring->days_of_week, true) ?? []) : [],
            ]
            : ['enabled' => false, 'type' => null, 'interval' => 1, 'daysOfWeek' => []];
    }

    private function parseOptionalDatetime(mixed $value): ?SupportCarbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $parsed = SupportCarbon::parse((string) $value);

            return $parsed;
        } catch (\Throwable $e) {
            Log::error('Failed to parse datetime', [
                'input' => $value,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
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

        $tasks = Task::query()
            ->with(['project', 'event', 'recurringTask', 'tags', 'collaborations'])
            ->forUser($userId)
            ->incomplete()
            ->relevantForDate($date)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

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

        return $filteredTasks
            ->map(function (Task $task) use ($date, $instancesByRecurringId): Task {
                if ($task->recurringTask !== null) {
                    $task->instanceForDate = $instancesByRecurringId->get($task->recurringTask->id);
                }
                $task->effectiveStatusForDate = $this->taskService->getEffectiveStatusForDate($task, $date);

                return $task;
            })
            ->values();
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

        $overdueTasks = Task::query()
            ->with(['project', 'tags', 'collaborations'])
            ->forUser($userId)
            ->incomplete()
            ->overdue($today)
            ->whereDoesntHave('recurringTask')
            ->orderByPriority()
            ->limit(50)
            ->get()
            ->map(fn (Task $task) => ['kind' => 'task', 'item' => $task]);

        $overdueEvents = Event::query()
            ->with(['tags', 'collaborations'])
            ->forUser($userId)
            ->notCancelled()
            ->where('status', '!=', EventStatus::Completed->value)
            ->overdue($today)
            ->whereDoesntHave('recurringEvent')
            ->orderBy('end_datetime')
            ->limit(50)
            ->get()
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

        $events = Event::query()
            ->with(['recurringEvent', 'tags', 'collaborations'])
            ->forUser($userId)
            ->notCancelled()
            ->where('status', '!=', EventStatus::Completed->value)
            ->activeForDate($date)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

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

        return $filteredEvents
            ->map(function (Event $event) use ($date, $instancesByRecurringId): Event {
                if ($event->recurringEvent !== null) {
                    $event->instanceForDate = $instancesByRecurringId->get($event->recurringEvent->id);
                }
                $event->effectiveStatusForDate = $this->eventService->getEffectiveStatusForDate($event, $date);

                return $event;
            })
            ->values();
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
