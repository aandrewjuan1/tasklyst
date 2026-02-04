<?php

namespace App\Services;

use App\Enums\TaskRecurrenceType;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TaskService
{
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
}
