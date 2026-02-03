<?php

namespace App\Livewire\Concerns;

use App\Models\Event;
use App\Models\Project;
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
        ];

        try {
            $task = $this->taskService->createTask($user, $taskAttributes);
        } catch (\Throwable $e) {
            Log::error('Failed to create task from workspace.', [
                'user_id' => $user->id,
                'payload' => $this->taskPayload,
                'exception' => $e,
            ]);

            $this->dispatch('toast', type: 'error', message: __('Something went wrong creating the task.'));

            return;
        }

        $this->listRefresh++;
        $this->dispatch('task-created', id: $task->id, title: $task->title);
        $this->dispatch('toast', type: 'success', message: __('Task created.'));
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

        $eventPayload = array_replace_recursive(EventPayloadValidation::defaults(), $payload);

        $validator = Validator::make(
            ['eventPayload' => $eventPayload],
            EventPayloadValidation::rules()
        );

        if ($validator->fails()) {
            Log::error('Event validation failed', [
                'errors' => $validator->errors()->all(),
                'payload' => $eventPayload,
            ]);
            $this->dispatch('toast', type: 'error', message: __('Please fix the event details and try again.'));

            return;
        }

        $validatedEvent = $validator->validated()['eventPayload'];

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

        $eventAttributes = [
            'title' => $title,
            'description' => $validatedEvent['description'] ?? null,
            'status' => $validatedEvent['status'] ?? null,
            'start_datetime' => $startDatetime,
            'end_datetime' => $endDatetime,
            'all_day' => $validatedEvent['allDay'] ?? false,
            'tagIds' => $tagIds,
        ];

        try {
            $event = $this->eventService->createEvent($user, $eventAttributes);
        } catch (\Throwable $e) {
            Log::error('Failed to create event from workspace.', [
                'user_id' => $user->id,
                'payload' => $eventPayload,
                'exception' => $e,
            ]);

            $this->dispatch('toast', type: 'error', message: __('Something went wrong creating the event.'));

            return;
        }

        $this->listRefresh++;
        $this->dispatch('event-created', id: $event->id, title: $event->title);
        $this->dispatch('toast', type: 'success', message: __('Event created.'));
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

            $this->dispatch('toast', type: 'error', message: __('Something went wrong deleting the task.'));

            return false;
        }

        if (! $deleted) {
            $this->dispatch('toast', type: 'error', message: __('Something went wrong deleting the task.'));

            return false;
        }

        $this->listRefresh++;
        $this->dispatch('toast', type: 'success', message: __('Task deleted.'));

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

        $task = Task::query()->find($taskId);

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
            try {
                $task->tags()->sync($validatedValue);
            } catch (\Throwable $e) {
                Log::error('Failed to sync task tags from workspace.', [
                    'user_id' => $user->id,
                    'task_id' => $taskId,
                    'exception' => $e,
                ]);
                $this->dispatch('toast', type: 'error', message: __('Something went wrong updating the task.'));

                return false;
            }
            if (! $silentToasts) {
                $this->dispatch('toast', type: 'success', message: __('Task updated.'));
            }

            return true;
        }

        $column = match ($property) {
            'startDatetime' => 'start_datetime',
            'endDatetime' => 'end_datetime',
            default => $property,
        };

        $attributes = [$column => $validatedValue];
        if ($column === 'start_datetime' || $column === 'end_datetime') {
            $attributes[$column] = $this->parseOptionalDatetime($validatedValue);
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
            $this->dispatch('toast', type: 'error', message: __('Something went wrong updating the task.'));

            return false;
        }

        $this->dispatch('toast', type: 'success', message: __('Task updated.'));

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

            $this->dispatch('toast', type: 'error', message: __('Something went wrong deleting the event.'));

            return false;
        }

        if (! $deleted) {
            $this->dispatch('toast', type: 'error', message: __('Something went wrong deleting the event.'));

            return false;
        }

        $this->listRefresh++;
        $this->dispatch('toast', type: 'success', message: __('Event deleted.'));

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

        $event = Event::query()->find($eventId);

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
            try {
                $event->tags()->sync($validatedValue);
            } catch (\Throwable $e) {
                Log::error('Failed to sync event tags from workspace.', [
                    'user_id' => $user->id,
                    'event_id' => $eventId,
                    'exception' => $e,
                ]);
                $this->dispatch('toast', type: 'error', message: __('Something went wrong updating the event.'));

                return false;
            }
            if (! $silentToasts) {
                $this->dispatch('toast', type: 'success', message: __('Event updated.'));
            }

            return true;
        }

        $column = match ($property) {
            'startDatetime' => 'start_datetime',
            'endDatetime' => 'end_datetime',
            'allDay' => 'all_day',
            default => $property,
        };

        $attributes = [$column => $validatedValue];
        if ($column === 'start_datetime' || $column === 'end_datetime') {
            $attributes[$column] = $this->parseOptionalDatetime($validatedValue);
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
            $this->dispatch('toast', type: 'error', message: __('Something went wrong updating the event.'));

            return false;
        }

        if (! $silentToasts) {
            $this->dispatch('toast', type: 'success', message: __('Event updated.'));
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

        return Task::query()
            ->with([
                'project',
                'event',
                'recurringTask',
                'tags',
                'collaborations',
            ])
            ->forUser($userId)
            ->incomplete()
            ->relevantForDate($date)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
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

        return Event::query()
            ->with([
                'recurringEvent',
                'tags',
                'collaborations',
            ])
            ->forUser($userId)
            ->activeForDate($date)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
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
