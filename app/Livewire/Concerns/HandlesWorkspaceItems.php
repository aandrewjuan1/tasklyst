<?php

namespace App\Livewire\Concerns;

use App\Enums\EventStatus;
use App\Enums\TaskStatus;
use App\Models\Event;
use App\Models\Project;
use App\Models\RecurringEvent;
use App\Models\RecurringTask;
use App\Models\Tag;
use App\Models\Task;
use App\Support\Validation\EventPayloadValidation;
use App\Support\Validation\ProjectPayloadValidation;
use App\Support\Validation\TaskPayloadValidation;
use Carbon\Carbon;
use Illuminate\Support\Carbon as SupportCarbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;

trait HandlesWorkspaceItems
{
    /**
     * Create a new task for the authenticated user.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createTask(array $payload): void
    {
        $user = Auth::user();

        if ($user === null) {
            $this->dispatch('toast', type: 'error', message: __('You must be logged in to create tasks.'));

            return;
        }

        $this->authorize('create', Task::class);

        $this->taskPayload = array_replace_recursive(TaskPayloadValidation::defaults(), $payload);

        try {
            /** @var array{taskPayload: array<string, mixed>} $validated */
            $validated = $this->validate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Task validation failed', [
                'errors' => $e->errors(),
                'payload' => $this->taskPayload,
            ]);
            $this->dispatch('toast', type: 'error', message: __('Please fix the task details and try again.'));

            return;
        }

        $validatedTask = $validated['taskPayload'];

        $title = (string) ($validatedTask['title'] ?? '');
        $startDatetime = $this->parseOptionalDatetime($validatedTask['startDatetime'] ?? null);
        $endDatetime = $this->parseOptionalDatetime($validatedTask['endDatetime'] ?? null);

        $tagIds = array_values(array_unique(array_map('intval', $validatedTask['tagIds'] ?? [])));
        foreach ($validatedTask['pendingTagNames'] ?? [] as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $existingTag = Tag::query()
                ->where('user_id', $user->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();
            if ($existingTag !== null) {
                $tagIds[] = $existingTag->id;

                continue;
            }
            try {
                $tag = $this->tagService->createTag($user, ['name' => $name]);
                $tagIds[] = $tag->id;
            } catch (\Throwable $e) {
                Log::error('Failed to create tag when creating task.', [
                    'user_id' => $user->id,
                    'name' => $name,
                    'exception' => $e,
                ]);
            }
        }
        $tagIds = array_values(array_unique($tagIds));

        $recurrenceData = $validatedTask['recurrence'] ?? null;
        $recurrenceEnabled = $recurrenceData['enabled'] ?? false;

        $taskAttributes = [
            'title' => $title,
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
        $user = Auth::user();

        if ($user === null) {
            $this->dispatch('toast', type: 'error', message: __('You must be logged in to create events.'));

            return;
        }

        $this->authorize('create', Event::class);

        $this->eventPayload = array_replace_recursive(EventPayloadValidation::defaults(), $payload);

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

        $tagIds = array_values(array_unique(array_map('intval', $validatedEvent['tagIds'] ?? [])));
        foreach ($validatedEvent['pendingTagNames'] ?? [] as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $existingTag = Tag::query()
                ->where('user_id', $user->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();
            if ($existingTag !== null) {
                $tagIds[] = $existingTag->id;

                continue;
            }
            try {
                $tag = $this->tagService->createTag($user, ['name' => $name]);
                $tagIds[] = $tag->id;
            } catch (\Throwable $e) {
                Log::error('Failed to create tag when creating event.', [
                    'user_id' => $user->id,
                    'name' => $name,
                    'exception' => $e,
                ]);
            }
        }
        $tagIds = array_values(array_unique($tagIds));

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
     * Create a new tag for the authenticated user.
     *
     * @param  bool  $silentToasts  When true, do not dispatch success/info toasts (e.g. when creating from list-item-card so only "Task updated." is shown).
     */
    public function createTag(string $name, bool $silentToasts = false): void
    {
        $user = Auth::user();

        if ($user === null) {
            $this->dispatch('toast', type: 'error', message: __('You must be logged in to create tags.'));

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
            $existingTag = Tag::query()
                ->where('user_id', $user->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($validatedName)])
                ->first();

            if ($existingTag !== null) {
                $this->dispatch('tag-created', id: $existingTag->id, name: $existingTag->name);
                if (! $silentToasts) {
                    $this->dispatch('toast', type: 'info', message: __('Tag already exists.'));
                }

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
    public function deleteTag(int $tagId, bool $silentToasts = false): void
    {
        $user = Auth::user();

        if ($user === null) {
            $this->dispatch('toast', type: 'error', message: __('You must be logged in to delete tags.'));

            return;
        }

        $tag = Tag::query()->find($tagId);

        if ($tag === null) {
            $this->dispatch('toast', type: 'error', message: __('Tag not found.'));

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
            $this->dispatch('toast', type: 'success', message: __('Tag deleted.'));
        }
        $this->dispatch('$refresh');
    }

    /**
     * Delete a task for the authenticated user.
     */
    public function deleteTask(int $taskId): bool
    {
        $user = Auth::user();

        if ($user === null) {
            $this->dispatch('toast', type: 'error', message: __('You must be logged in to delete tasks.'));

            return false;
        }

        $task = Task::query()->find($taskId);

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

        $this->listRefresh++;
        $this->dispatch('toast', ...Task::toastPayload('delete', true, $task->title));

        return true;
    }

    /**
     * Update a single task property for the authenticated user (inline editing).
     *
     * @param  bool  $silentToasts  When true, do not dispatch success toast (e.g. when syncing tagIds after delete so only "Tag deleted." is shown).
     */
    public function updateTaskProperty(int $taskId, string $property, mixed $value, bool $silentToasts = false): bool
    {
        $user = Auth::user();

        if ($user === null) {
            $this->dispatch('toast', type: 'error', message: __('You must be logged in to update tasks.'));

            return false;
        }

        $task = Task::query()->with('recurringTask')->find($taskId);

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
                $this->dispatch('toast', ...Task::toastPayloadForPropertyUpdate('tagIds', $oldTagIds, $validatedValue, true, $task->title));
            }

            return true;
        }

        if ($property === 'recurrence') {
            $task->loadMissing('recurringTask');
            $oldRecurrence = $task->recurringTask
                ? [
                    'enabled' => true,
                    'type' => $task->recurringTask->recurrence_type?->value,
                    'interval' => $task->recurringTask->interval ?? 1,
                    'daysOfWeek' => $task->recurringTask->days_of_week ? (json_decode($task->recurringTask->days_of_week, true) ?? []) : [],
                ]
                : [
                    'enabled' => false,
                    'type' => null,
                    'interval' => 1,
                    'daysOfWeek' => [],
                ];
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
                    $date = Carbon::parse($this->selectedDate);
                    $oldStatus = $this->taskService->getEffectiveStatusForDate($task, $date)?->value;
                    $this->taskService->updateRecurringOccurrenceStatus($task, $date, TaskStatus::from($validatedValue));
                    $this->listRefresh++;
                    if (! $silentToasts) {
                        $this->dispatch('toast', ...Task::toastPayloadForPropertyUpdate('status', $oldStatus, $validatedValue, true, $task->title));
                    }

                    return true;
                } catch (\Throwable $e) {
                    Log::error('Failed to update recurring task occurrence status from workspace.', [
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
    public function deleteProject(int $projectId): bool
    {
        $user = Auth::user();

        if ($user === null) {
            $this->dispatch('toast', type: 'error', message: __('You must be logged in to delete projects.'));

            return false;
        }

        $project = Project::query()->find($projectId);

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

            $this->dispatch('toast', type: 'error', message: __('Something went wrong deleting the project.'));

            return false;
        }

        if (! $deleted) {
            $this->dispatch('toast', type: 'error', message: __('Something went wrong deleting the project.'));

            return false;
        }

        $this->listRefresh++;
        $this->dispatch('toast', type: 'success', message: __('Project deleted.'));

        return true;
    }

    /**
     * Delete an event for the authenticated user.
     */
    public function deleteEvent(int $eventId): bool
    {
        $user = Auth::user();

        if ($user === null) {
            $this->dispatch('toast', type: 'error', message: __('You must be logged in to delete events.'));

            return false;
        }

        $event = Event::query()->find($eventId);

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

        $this->listRefresh++;
        $this->dispatch('toast', ...Event::toastPayload('delete', true, $event->title));

        return true;
    }

    /**
     * Update a single event property for the authenticated user (inline editing).
     *
     * @param  bool  $silentToasts  When true, do not dispatch success toast (e.g. when syncing tagIds after delete so only "Tag deleted." is shown).
     */
    public function updateEventProperty(int $eventId, string $property, mixed $value, bool $silentToasts = false): bool
    {
        $user = Auth::user();

        if ($user === null) {
            $this->dispatch('toast', type: 'error', message: __('You must be logged in to update events.'));

            return false;
        }

        $event = Event::query()->with('recurringEvent')->find($eventId);

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
                $this->dispatch('toast', ...Event::toastPayloadForPropertyUpdate('tagIds', $oldTagIds, $validatedValue, true, $event->title));
            }

            return true;
        }

        if ($property === 'recurrence') {
            $event->loadMissing('recurringEvent');
            $oldRecurrence = $event->recurringEvent
                ? [
                    'enabled' => true,
                    'type' => $event->recurringEvent->recurrence_type?->value,
                    'interval' => $event->recurringEvent->interval ?? 1,
                    'daysOfWeek' => $event->recurringEvent->days_of_week ? (json_decode($event->recurringEvent->days_of_week, true) ?? []) : [],
                ]
                : [
                    'enabled' => false,
                    'type' => null,
                    'interval' => 1,
                    'daysOfWeek' => [],
                ];
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
                    $date = Carbon::parse($this->selectedDate);
                    $oldStatus = $this->eventService->getEffectiveStatusForDate($event, $date)?->value;
                    $this->eventService->updateRecurringOccurrenceStatus($event, $date, EventStatus::from($validatedValue));
                    $this->listRefresh++;
                    if (! $silentToasts) {
                        $this->dispatch('toast', ...Event::toastPayloadForPropertyUpdate('status', $oldStatus, $validatedValue, true, $event->title));
                    }

                    return true;
                } catch (\Throwable $e) {
                    Log::error('Failed to update recurring event occurrence status from workspace.', [
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
    public function updateProjectProperty(int $projectId, string $property, mixed $value, bool $silentToasts = false): bool
    {
        $user = Auth::user();

        if ($user === null) {
            $this->dispatch('toast', type: 'error', message: __('You must be logged in to update projects.'));

            return false;
        }

        $project = Project::query()->find($projectId);

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

        $column = match ($property) {
            default => $property,
        };

        $attributes = [$column => $validatedValue];

        try {
            $this->projectService->updateProject($project, $attributes);
        } catch (\Throwable $e) {
            Log::error('Failed to update project property from workspace.', [
                'user_id' => $user->id,
                'project_id' => $projectId,
                'property' => $property,
                'exception' => $e,
            ]);
            $this->dispatch('toast', type: 'error', message: __('Something went wrong updating the project.'));

            return false;
        }

        if (! $silentToasts) {
            $this->dispatch('toast', type: 'success', message: __('Project updated.'));
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
            ->with([
                'project',
                'event',
                'recurringTask.taskInstances' => fn ($q) => $q->where('instance_date', $date->format('Y-m-d')),
                'tags',
                'collaborations',
            ])
            ->forUser($userId)
            ->incomplete()
            ->relevantForDate($date)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return $tasks
            ->filter(fn (Task $task) => $this->taskService->isTaskRelevantForDate($task, $date))
            ->map(function (Task $task) use ($date): Task {
                $task->effectiveStatusForDate = $this->taskService->getEffectiveStatusForDate($task, $date);

                return $task;
            })
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
            ->with([
                'recurringEvent.eventInstances' => fn ($q) => $q->whereDate('instance_date', $date->format('Y-m-d')),
                'tags',
                'collaborations',
            ])
            ->forUser($userId)
            ->activeForDate($date)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return $events
            ->filter(fn (Event $event) => $this->eventService->isEventActiveForDate($event, $date))
            ->map(function (Event $event) use ($date): Event {
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
