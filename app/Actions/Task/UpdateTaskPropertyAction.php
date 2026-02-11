<?php

namespace App\Actions\Task;

use App\DataTransferObjects\Task\UpdateTaskPropertyResult;
use App\Enums\TaskStatus;
use App\Models\RecurringTask;
use App\Models\Tag;
use App\Models\Task;
use App\Services\TaskService;
use App\Support\DateHelper;
use App\Support\Validation\TaskPayloadValidation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

class UpdateTaskPropertyAction
{
    public function __construct(
        private TaskService $taskService
    ) {}

    public function execute(Task $task, string $property, mixed $validatedValue, ?string $occurrenceDate = null): UpdateTaskPropertyResult
    {
        if ($property === 'tagIds') {
            return $this->updateTagIds($task, $validatedValue);
        }

        if ($property === 'recurrence') {
            return $this->updateRecurrence($task, $validatedValue);
        }

        if ($property === 'status') {
            $recurringTask = $task->recurringTask ?? RecurringTask::where('task_id', $task->id)->first();
            if ($recurringTask !== null) {
                return $this->updateRecurringStatus($task, $validatedValue, $occurrenceDate);
            }
        }

        return $this->updateSimpleProperty($task, $property, $validatedValue);
    }

    private function updateTagIds(Task $task, mixed $validatedValue): UpdateTaskPropertyResult
    {
        Log::info('[TAG-SYNC] Starting task tag update', [
            'task_id' => $task->id,
            'task_title' => $task->title,
            'validated_value' => $validatedValue,
            'validated_value_type' => gettype($validatedValue),
            'validated_value_is_array' => is_array($validatedValue),
        ]);

        $oldTagIds = $task->tags()->pluck('tags.id')->all();

        Log::info('[TAG-SYNC] Retrieved current task tags', [
            'task_id' => $task->id,
            'old_tag_ids' => $oldTagIds,
            'old_tag_ids_count' => count($oldTagIds),
        ]);

        $addedIds = array_values(array_diff($validatedValue, $oldTagIds));
        $removedIds = array_values(array_diff($oldTagIds, $validatedValue));

        Log::info('[TAG-SYNC] Calculated tag changes', [
            'task_id' => $task->id,
            'added_ids' => $addedIds,
            'removed_ids' => $removedIds,
            'added_count' => count($addedIds),
            'removed_count' => count($removedIds),
        ]);

        $addedTagName = count($addedIds) === 1 ? (Tag::find($addedIds[0])?->name ?? null) : null;
        $removedTagName = count($removedIds) === 1 ? (Tag::find($removedIds[0])?->name ?? null) : null;

        if ($addedTagName || $removedTagName) {
            Log::info('[TAG-SYNC] Tag names for toast', [
                'task_id' => $task->id,
                'added_tag_name' => $addedTagName,
                'removed_tag_name' => $removedTagName,
            ]);
        }

        try {
            Log::info('[TAG-SYNC] About to sync tags', [
                'task_id' => $task->id,
                'sync_ids' => $validatedValue,
            ]);

            $task->tags()->sync($validatedValue);

            Log::info('[TAG-SYNC] Successfully synced task tags', [
                'task_id' => $task->id,
                'old_tag_ids' => $oldTagIds,
                'new_tag_ids' => $validatedValue,
            ]);

            return UpdateTaskPropertyResult::success($oldTagIds, $validatedValue, $addedTagName, $removedTagName);
        } catch (\Throwable $e) {
            Log::error('[TAG-SYNC] Failed to sync task tags from workspace', [
                'task_id' => $task->id,
                'old_tag_ids' => $oldTagIds,
                'new_tag_ids' => $validatedValue,
                'exception' => $e->getMessage(),
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return UpdateTaskPropertyResult::failure($oldTagIds, $validatedValue);
        }
    }

    private function updateRecurrence(Task $task, mixed $validatedValue): UpdateTaskPropertyResult
    {
        $task->loadMissing('recurringTask');
        $oldRecurrence = RecurringTask::toPayloadArray($task->recurringTask);

        try {
            $this->taskService->updateOrCreateRecurringTask($task, $validatedValue);

            return UpdateTaskPropertyResult::success($oldRecurrence, $validatedValue);
        } catch (\Throwable $e) {
            Log::error('Failed to update task recurrence from workspace.', [
                'task_id' => $task->id,
                'exception' => $e,
            ]);

            return UpdateTaskPropertyResult::failure($oldRecurrence, $validatedValue);
        }
    }

    private function updateRecurringStatus(Task $task, mixed $validatedValue, ?string $occurrenceDate): UpdateTaskPropertyResult
    {
        $recurringTask = $task->recurringTask ?? RecurringTask::where('task_id', $task->id)->first();
        if ($recurringTask === null) {
            return UpdateTaskPropertyResult::failure($task->status?->value, $validatedValue);
        }

        $task->setRelation('recurringTask', $recurringTask);
        $oldStatus = $task->status?->value;
        $statusEnum = TaskStatus::tryFrom($validatedValue) ?? $task->status;

        try {
            if ($occurrenceDate !== null && $occurrenceDate !== '') {
                $this->taskService->updateRecurringOccurrenceStatus($task, Date::parse($occurrenceDate), $statusEnum);
            } else {
                $this->taskService->updateTask($task, ['status' => $validatedValue]);
            }

            return UpdateTaskPropertyResult::success($oldStatus, $validatedValue);
        } catch (\Throwable $e) {
            Log::error('Failed to update recurring task status from workspace.', [
                'task_id' => $task->id,
                'exception' => $e,
            ]);

            return UpdateTaskPropertyResult::failure($task->status?->value, $validatedValue);
        }
    }

    private function updateSimpleProperty(Task $task, string $property, mixed $validatedValue): UpdateTaskPropertyResult
    {
        $column = Task::propertyToColumn($property);
        $oldValue = $task->getPropertyValueForUpdate($property);

        $attributes = [$column => $validatedValue];
        if ($column === 'start_datetime' || $column === 'end_datetime') {
            $parsedDatetime = DateHelper::parseOptional($validatedValue);
            $attributes[$column] = $parsedDatetime;

            $start = $column === 'start_datetime' ? $parsedDatetime : $task->start_datetime;
            $end = $column === 'end_datetime' ? $parsedDatetime : $task->end_datetime;
            $durationMinutes = (int) ($task->duration ?? 0);

            $dateRangeError = TaskPayloadValidation::validateTaskDateRangeForUpdate($start, $end, $durationMinutes);
            if ($dateRangeError !== null) {
                return UpdateTaskPropertyResult::failure($oldValue, $validatedValue, $dateRangeError);
            }
        }

        try {
            $this->taskService->updateTask($task, $attributes);
        } catch (\Throwable $e) {
            Log::error('Failed to update task property from workspace.', [
                'task_id' => $task->id,
                'property' => $property,
                'exception' => $e,
            ]);

            return UpdateTaskPropertyResult::failure($oldValue, $validatedValue);
        }

        $newValue = in_array($property, ['startDatetime', 'endDatetime'], true) ? ($attributes[$column] ?? null) : $validatedValue;

        return UpdateTaskPropertyResult::success($oldValue, $newValue);
    }
}
