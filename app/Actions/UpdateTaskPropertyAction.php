<?php

namespace App\Actions;

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
        $oldTagIds = $task->tags()->pluck('tags.id')->all();
        $addedIds = array_values(array_diff($validatedValue, $oldTagIds));
        $removedIds = array_values(array_diff($oldTagIds, $validatedValue));
        $addedTagName = count($addedIds) === 1 ? (Tag::find($addedIds[0])?->name ?? null) : null;
        $removedTagName = count($removedIds) === 1 ? (Tag::find($removedIds[0])?->name ?? null) : null;

        try {
            $task->tags()->sync($validatedValue);

            return UpdateTaskPropertyResult::success($oldTagIds, $validatedValue, $addedTagName, $removedTagName);
        } catch (\Throwable $e) {
            Log::error('Failed to sync task tags from workspace.', [
                'task_id' => $task->id,
                'exception' => $e,
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
