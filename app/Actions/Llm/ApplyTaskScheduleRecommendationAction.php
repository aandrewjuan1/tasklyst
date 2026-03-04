<?php

namespace App\Actions\Llm;

use App\Actions\Task\UpdateTaskPropertyAction;
use App\DataTransferObjects\Llm\TaskScheduleRecommendationDto;
use App\Enums\ActivityLogAction;
use App\Enums\LlmIntent;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogRecorder;

class ApplyTaskScheduleRecommendationAction
{
    public function __construct(
        private UpdateTaskPropertyAction $updateTaskProperty,
        private ActivityLogRecorder $activityLogRecorder
    ) {}

    /**
     * Apply a task scheduling or deadline-adjustment recommendation to the given task.
     *
     * @param  array<string, mixed>  $overrides  Optional user-modified values for startDatetime, endDatetime, duration, priority.
     */
    public function execute(
        User $user,
        Task $task,
        TaskScheduleRecommendationDto $recommendation,
        LlmIntent $intent,
        string $userAction,
        array $overrides = []
    ): void {
        if ($userAction === 'reject') {
            $this->recordAudit($task, $user, $intent, $userAction, $recommendation, []);

            return;
        }

        if (! $this->isScheduleAcceptable($task, $recommendation, $intent)) {
            $this->recordAudit($task, $user, $intent, $userAction, $recommendation, []);

            return;
        }

        $changes = [];

        $attributes = $recommendation->toTaskAttributes();
        $attributes = array_merge($attributes, $overrides);

        foreach (['startDatetime', 'endDatetime', 'duration', 'priority'] as $property) {
            if (! array_key_exists($property, $attributes)) {
                continue;
            }

            $oldValue = $task->getPropertyValueForUpdate($property);
            $newValue = $attributes[$property];

            if ($oldValue === $newValue) {
                continue;
            }

            $this->updateTaskProperty->execute($task, $property, $newValue);

            $changes[$property] = [
                'from' => $oldValue,
                'to' => $newValue,
            ];
        }

        $this->recordAudit($task, $user, $intent, $userAction, $recommendation, $changes);
    }

    /**
     * @param  array<string, array{from:mixed,to:mixed}>  $changes
     */
    private function recordAudit(
        Task $task,
        User $user,
        LlmIntent $intent,
        string $userAction,
        TaskScheduleRecommendationDto $recommendation,
        array $changes
    ): void {
        $this->activityLogRecorder->record(
            $task,
            $user,
            ActivityLogAction::FieldUpdated,
            [
                'field' => 'llm_recommendation',
                'from' => null,
                'to' => [
                    'intent' => $intent->value,
                    'entity_type' => 'task',
                    'user_action' => $userAction,
                    'reasoning' => $recommendation->reasoning,
                    'changes' => $changes,
                    'modified_fields' => array_keys($changes),
                ],
            ]
        );
    }

    private function isScheduleAcceptable(Task $task, TaskScheduleRecommendationDto $recommendation, LlmIntent $intent): bool
    {
        $now = now();
        $start = $recommendation->startDatetime;
        $end = $recommendation->endDatetime;

        // Disallow recommendations that end fully in the past.
        if ($end !== null && $end->lt($now)) {
            return false;
        }

        // For scheduling or deadline-adjustment intents, keep suggested end within the task's due date when present.
        if (in_array($intent, [LlmIntent::ScheduleTask, LlmIntent::AdjustTaskDeadline], true)
            && $task->end_datetime !== null
            && $end !== null
            && $end->gt($task->end_datetime->copy()->endOfDay())
        ) {
            return false;
        }

        return true;
    }
}
