<?php

namespace App\Services;

use App\Enums\ActivityLogAction;
use App\Enums\TaskRecurrenceType;
use App\Enums\TaskStatus;
use App\Models\CollaborationInvitation;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\TaskException;
use App\Models\TaskInstance;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TaskService
{
    public function __construct(
        private ActivityLogRecorder $activityLogRecorder,
        private RecurrenceExpander $recurrenceExpander
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createTask(User $user, array $attributes): Task
    {
        return DB::transaction(function () use ($user, $attributes): Task {
            $tagIds = $attributes['tagIds'] ?? [];
            unset($attributes['tagIds']);

            $recurrenceData = $attributes['recurrence'] ?? null;
            unset($attributes['recurrence']);

            $task = Task::query()->create([
                ...$attributes,
                'user_id' => $user->id,
            ]);

            if (! empty($tagIds)) {
                $task->tags()->attach($tagIds);
            }

            if ($recurrenceData !== null && ($recurrenceData['enabled'] ?? false)) {
                $this->createRecurringTask($task, $recurrenceData);
            }

            $this->activityLogRecorder->record($task, $user, ActivityLogAction::ItemCreated, [
                'title' => $task->title,
            ]);

            return $task;
        });
    }

    /**
     * Update or create RecurringTask for the given task based on recurrence data.
     * If enabled is false, deletes existing RecurringTask. If enabled is true and type is set, creates or updates.
     *
     * @param  array<string, mixed>  $recurrenceData
     */
    public function updateOrCreateRecurringTask(Task $task, array $recurrenceData): void
    {
        DB::transaction(function () use ($task, $recurrenceData): void {
            $task->recurringTask?->delete();

            if (($recurrenceData['enabled'] ?? false) && ($recurrenceData['type'] ?? null) !== null) {
                $this->createRecurringTask($task, $recurrenceData);
            }
        });
    }

    /**
     * Create a RecurringTask record for the given task.
     *
     * @param  array<string, mixed>  $recurrenceData
     */
    private function createRecurringTask(Task $task, array $recurrenceData): void
    {
        $recurrenceType = $recurrenceData['type'] ?? null;
        if ($recurrenceType === null) {
            return;
        }

        $recurrenceTypeEnum = TaskRecurrenceType::from($recurrenceType);
        $interval = max(1, (int) ($recurrenceData['interval'] ?? 1));
        $daysOfWeek = $recurrenceData['daysOfWeek'] ?? [];

        // Use task's start_datetime and end_datetime for the recurring task.
        // When both are null, the item is treated as "always relevant" in the list.
        $startDatetime = $task->start_datetime;
        $endDatetime = $task->end_datetime;

        // Convert days_of_week array to JSON string for storage
        $daysOfWeekString = null;
        if (is_array($daysOfWeek) && ! empty($daysOfWeek)) {
            $daysOfWeekString = json_encode($daysOfWeek, JSON_THROW_ON_ERROR);
        }

        RecurringTask::query()->create([
            'task_id' => $task->id,
            'recurrence_type' => $recurrenceTypeEnum,
            'interval' => $interval,
            'start_datetime' => $startDatetime,
            'end_datetime' => $endDatetime,
            'days_of_week' => $daysOfWeekString,
        ]);

        $this->normalizeBaseStatusForRecurringTask($task);
    }

    /**
     * When enabling recurrence with a non-default status (e.g. Doing, Done), create an instance
     * for today to preserve that status, and reset base status to To Do for future occurrences.
     */
    private function normalizeBaseStatusForRecurringTask(Task $task): void
    {
        $task->load('recurringTask');
        if ($task->recurringTask === null) {
            return;
        }

        if ($task->status === null || $task->status === TaskStatus::ToDo) {
            return;
        }

        $today = now()->toDateString();
        $startOfDay = now()->copy()->startOfDay();
        $endOfDay = now()->copy()->endOfDay();
        $occurrences = $this->recurrenceExpander->expand($task->recurringTask, $startOfDay, $endOfDay);
        if (collect($occurrences)->contains(fn ($d) => $d->format('Y-m-d') === $today)) {
            $this->updateRecurringOccurrenceStatus($task, now(), $task->status);
        }

        $task->update(['status' => TaskStatus::ToDo]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateTask(Task $task, array $attributes): Task
    {
        unset($attributes['user_id']);

        if (array_key_exists('status', $attributes)) {
            $status = $attributes['status'] instanceof TaskStatus
                ? $attributes['status']
                : TaskStatus::tryFrom((string) $attributes['status']);
            $attributes['completed_at'] = $status === TaskStatus::Done ? now() : null;
        }

        return DB::transaction(function () use ($task, $attributes): Task {
            $task->fill($attributes);
            $task->save();

            $this->syncRecurringTaskDatesIfNeeded($task, $attributes);

            return $task;
        });
    }

    public function deleteTask(Task $task, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($task, $actor): bool {
            $this->activityLogRecorder->record($task, $actor, ActivityLogAction::ItemDeleted, [
                'title' => $task->title,
            ]);

            return (bool) $task->delete();
        });
    }

    public function restoreTask(Task $task, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($task, $actor): bool {
            $this->activityLogRecorder->record($task, $actor, ActivityLogAction::ItemRestored, [
                'title' => $task->title,
            ]);

            return (bool) $task->restore();
        });
    }

    public function forceDeleteTask(Task $task, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($task, $actor): bool {
            $this->activityLogRecorder->record($task, $actor, ActivityLogAction::ItemDeleted, [
                'title' => $task->title,
            ]);

            CollaborationInvitation::query()
                ->where('collaboratable_type', $task->getMorphClass())
                ->where('collaboratable_id', $task->id)
                ->delete();

            return (bool) $task->forceDelete();
        });
    }

    /**
     * Create or update a TaskInstance for the given recurring task occurrence date with any status.
     * Does not modify the parent Task.
     */
    public function updateRecurringOccurrenceStatus(Task $task, CarbonInterface $date, TaskStatus $status): TaskInstance
    {
        $recurringTask = $task->recurringTask;
        if ($recurringTask === null) {
            throw new \InvalidArgumentException('Task must have a recurring task to update an occurrence status.');
        }

        $instanceDate = $date instanceof \DateTimeInterface
            ? $date->format('Y-m-d')
            : \Carbon\Carbon::parse($date)->format('Y-m-d');

        $attributes = [
            'task_id' => $task->id,
            'status' => $status,
            'completed_at' => $status === TaskStatus::Done ? now() : null,
        ];

        $instance = TaskInstance::query()
            ->where('recurring_task_id', $recurringTask->id)
            ->whereDate('instance_date', $instanceDate)
            ->first();

        if ($instance !== null) {
            $instance->update($attributes);

            return $instance->fresh();
        }

        return TaskInstance::query()->create([
            'recurring_task_id' => $recurringTask->id,
            'task_id' => $task->id,
            'instance_date' => $instanceDate,
            'status' => $status,
            'completed_at' => $status === TaskStatus::Done ? now() : null,
        ]);
    }

    /**
     * Create or update a TaskInstance for the given recurring task occurrence date.
     * Marks the occurrence as done. Does not modify the parent Task.
     */
    public function completeRecurringOccurrence(Task $task, CarbonInterface $date): TaskInstance
    {
        return $this->updateRecurringOccurrenceStatus($task, $date, TaskStatus::Done);
    }

    /**
     * Get the effective status for a task on a given date.
     * For recurring tasks: returns instance status when instanceForDate is set (lazy instance), else base task status.
     * For non-recurring: returns base task status.
     */
    public function getEffectiveStatusForDate(Task $task, CarbonInterface $date): TaskStatus
    {
        $instance = $task->instanceForDate ?? null;

        if ($instance instanceof TaskInstance) {
            return $instance->status ?? TaskStatus::ToDo;
        }

        return $task->status ?? TaskStatus::ToDo;
    }

    /**
     * Get the effective status for a task on a given date by resolving the instance from the DB when the task is recurring.
     * Use this when the task does not have instanceForDate set (e.g. outside list processing).
     */
    public function getEffectiveStatusForDateResolved(Task $task, CarbonInterface $date): TaskStatus
    {
        $task->loadMissing('recurringTask');
        $recurringTask = $task->recurringTask;

        if ($recurringTask !== null) {
            $instanceDate = $date instanceof \DateTimeInterface
                ? $date->format('Y-m-d')
                : \Carbon\Carbon::parse($date)->format('Y-m-d');
            $instance = TaskInstance::query()
                ->where('recurring_task_id', $recurringTask->id)
                ->whereDate('instance_date', $instanceDate)
                ->first();

            if ($instance instanceof TaskInstance) {
                return $instance->status ?? TaskStatus::ToDo;
            }
        }

        return $task->status ?? TaskStatus::ToDo;
    }

    /**
     * Process recurring tasks for a given date: filter by relevant occurrences,
     * batch-load TaskInstance records, and set instanceForDate and effectiveStatusForDate on each task.
     *
     * @param  Collection<int, Task>  $tasks  Tasks with recurringTask relation loaded
     * @return Collection<int, Task>
     */
    public function processRecurringTasksForDate(Collection $tasks, CarbonInterface $date): Collection
    {
        // Early return if no tasks
        if ($tasks->isEmpty()) {
            return $tasks;
        }

        $recurringTasks = $tasks->pluck('recurringTask')->filter();

        // Early return if no recurring tasks - skip all processing
        if ($recurringTasks->isEmpty()) {
            return $tasks->map(function (Task $task) use ($date): Task {
                $task->effectiveStatusForDate = $this->getEffectiveStatusForDate($task, $date);

                return $task;
            })->values();
        }

        $relevantIds = $this->recurrenceExpander->getRelevantRecurringIdsForDate($recurringTasks, collect(), $date);
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
                ->select(['id', 'recurring_task_id', 'instance_date', 'status', 'created_at', 'updated_at'])
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
                $task->effectiveStatusForDate = $this->getEffectiveStatusForDate($task, $date);

                return $task;
            })
            ->values();
    }

    /**
     * Check if a task is relevant for the given date (should appear in workspace).
     * For recurring tasks: date must be in expanded occurrences (show task every occurrence day).
     * For non-recurring: returns true (scope already filtered).
     */
    public function isTaskRelevantForDate(Task $task, CarbonInterface $date): bool
    {
        $recurringTask = $task->recurringTask;
        if ($recurringTask === null) {
            return true;
        }

        $dateStr = $date instanceof \DateTimeInterface
            ? $date->format('Y-m-d')
            : \Carbon\Carbon::parse($date)->format('Y-m-d');

        $occurrences = $this->getOccurrencesForDateRange($recurringTask, $date, $date);

        return collect($occurrences)->contains(fn ($d) => $d->format('Y-m-d') === $dateStr);
    }

    /**
     * Expand recurrence pattern into concrete dates within the range.
     * Respects TaskException (excludes deleted, applies replacements).
     *
     * @return array<CarbonInterface>
     */
    public function getOccurrencesForDateRange(RecurringTask $recurringTask, CarbonInterface $start, CarbonInterface $end): array
    {
        return $this->recurrenceExpander->expand($recurringTask, $start, $end);
    }

    /**
     * Sync RecurringTask start_datetime and end_datetime when the parent Task's dates change.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function syncRecurringTaskDatesIfNeeded(Task $task, array $attributes): void
    {
        $dateKeys = ['start_datetime', 'end_datetime'];
        $hasDateChanges = array_intersect(array_keys($attributes), $dateKeys) !== [];

        if (! $hasDateChanges) {
            return;
        }

        $recurringTask = $task->recurringTask ?? RecurringTask::where('task_id', $task->id)->first();
        if ($recurringTask === null) {
            return;
        }

        $syncAttributes = [];
        if (array_key_exists('start_datetime', $attributes)) {
            $syncAttributes['start_datetime'] = $attributes['start_datetime'];
        }
        if (array_key_exists('end_datetime', $attributes)) {
            $syncAttributes['end_datetime'] = $attributes['end_datetime'];
        }

        if ($syncAttributes !== []) {
            $recurringTask->update($syncAttributes);
        }
    }

    /**
     * Create a TaskException to skip or replace an occurrence.
     */
    public function createTaskException(
        RecurringTask $recurringTask,
        CarbonInterface $date,
        bool $isDeleted,
        ?TaskInstance $replacement = null,
        ?User $createdBy = null,
        ?string $reason = null
    ): TaskException {
        $exceptionDate = $date instanceof \DateTimeInterface
            ? $date->format('Y-m-d')
            : \Carbon\Carbon::parse($date)->format('Y-m-d');

        return TaskException::query()->updateOrCreate(
            [
                'recurring_task_id' => $recurringTask->id,
                'exception_date' => $exceptionDate,
            ],
            [
                'is_deleted' => $isDeleted,
                'replacement_instance_id' => $replacement?->id,
                'created_by' => $createdBy?->id,
                'reason' => $reason,
            ]
        );
    }

    /**
     * Delete a TaskException. After deletion, the occurrence will again be included in recurrence expansion.
     */
    public function deleteTaskException(TaskException $exception): bool
    {
        return (bool) $exception->delete();
    }

    /**
     * Get exceptions for a recurring task, optionally filtered by date range.
     *
     * @return Collection<int, TaskException>
     */
    public function getExceptionsForRecurringTask(
        RecurringTask $recurringTask,
        ?CarbonInterface $start = null,
        ?CarbonInterface $end = null
    ): Collection {
        $query = $recurringTask->taskExceptions();

        if ($start !== null) {
            $query->whereDate('exception_date', '>=', $start);
        }
        if ($end !== null) {
            $query->whereDate('exception_date', '<=', $end);
        }

        return $query->orderBy('exception_date')->get();
    }

    /**
     * Update a TaskException. Only is_deleted, reason, and replacement_instance_id can be updated.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateTaskException(TaskException $exception, array $attributes): TaskException
    {
        $allowed = ['is_deleted', 'reason', 'replacement_instance_id'];
        $filtered = array_intersect_key($attributes, array_flip($allowed));

        if ($filtered !== []) {
            $exception->fill($filtered);
            $exception->save();
        }

        return $exception->fresh();
    }
}
