<?php

namespace App\Services;

use App\Enums\TaskRecurrenceType;
use App\Enums\TaskStatus;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\TaskException;
use App\Models\TaskInstance;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class TaskService
{
    public function __construct(
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

        // Use task's start_datetime and end_datetime for the recurring task
        $startDatetime = $task->start_datetime;
        $endDatetime = $task->end_datetime;

        // Require start_datetime for recurring tasks - use current time if task doesn't have one
        if ($startDatetime === null) {
            $startDatetime = now();
        }

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

            return $task;
        });
    }

    public function deleteTask(Task $task): bool
    {
        return DB::transaction(function () use ($task): bool {
            return (bool) $task->delete();
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
     * For recurring tasks: returns instance status if one exists for that date, otherwise ToDo (each occurrence starts fresh).
     * For non-recurring: returns base task status.
     * Uses eager-loaded taskInstances when available to avoid N+1 queries.
     */
    public function getEffectiveStatusForDate(Task $task, CarbonInterface $date): TaskStatus
    {
        $recurringTask = $task->recurringTask;
        if ($recurringTask === null) {
            return $task->status ?? TaskStatus::ToDo;
        }

        $dateStr = $date instanceof \DateTimeInterface
            ? $date->format('Y-m-d')
            : \Carbon\Carbon::parse($date)->format('Y-m-d');

        $instance = $recurringTask->relationLoaded('taskInstances')
            ? $recurringTask->taskInstances->first()
            : TaskInstance::query()
                ->where('recurring_task_id', $recurringTask->id)
                ->whereDate('instance_date', $dateStr)
                ->first();

        return $instance?->status ?? TaskStatus::ToDo;
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
     * Create a TaskException to skip or replace an occurrence.
     */
    public function createTaskException(
        RecurringTask $recurringTask,
        CarbonInterface $date,
        bool $isDeleted,
        ?TaskInstance $replacement = null,
        ?User $createdBy = null
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
            ]
        );
    }
}
