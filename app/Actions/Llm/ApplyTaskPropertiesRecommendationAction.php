<?php

namespace App\Actions\Llm;

use App\Actions\Task\UpdateTaskPropertyAction;
use App\DataTransferObjects\Llm\TaskUpdatePropertiesRecommendationDto;
use App\Enums\ActivityLogAction;
use App\Enums\LlmIntent;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogRecorder;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ApplyTaskPropertiesRecommendationAction
{
    /**
     * @var list<string>
     */
    private const ALLOWED_PROPERTIES = [
        'title',
        'description',
        'status',
        'priority',
        'complexity',
        'duration',
        'startDatetime',
        'endDatetime',
        'tagNames',
    ];

    public function __construct(
        private UpdateTaskPropertyAction $updateTaskProperty,
        private ActivityLogRecorder $activityLogRecorder
    ) {}

    /**
     * Apply a generic task property update recommendation to the given task.
     *
     * @param  array<string, mixed>  $overrides  Optional user-modified values for properties.
     */
    public function execute(
        User $user,
        Task $task,
        TaskUpdatePropertiesRecommendationDto $recommendation,
        LlmIntent $intent,
        string $userAction,
        array $overrides = []
    ): void {
        if ($userAction === 'reject') {
            $this->recordAudit($task, $user, $intent, $userAction, $recommendation, []);

            return;
        }

        $properties = array_merge($recommendation->proposedProperties(), $overrides);
        $properties = array_intersect_key($properties, array_flip(self::ALLOWED_PROPERTIES));

        if (in_array($intent, [LlmIntent::ScheduleTask, LlmIntent::AdjustTaskDeadline], true)) {
            unset($properties['endDatetime']);
        }

        if ($properties === []) {
            $this->recordAudit($task, $user, $intent, $userAction, $recommendation, []);

            return;
        }

        if (in_array($intent, [LlmIntent::ScheduleTask, LlmIntent::AdjustTaskDeadline], true)
            && ! $this->isScheduleAcceptableFromProperties($task, $properties, $intent)
        ) {
            $this->recordAudit($task, $user, $intent, $userAction, $recommendation, []);

            return;
        }

        $changes = [];

        DB::transaction(function () use (&$changes, $task, $user, $properties): void {
            foreach ($properties as $property => $newValue) {
                if ($property === 'tagNames') {
                    // tagNames is translated into tagIds elsewhere; skip direct application here.
                    continue;
                }

                $oldValue = $task->getPropertyValueForUpdate($property);

                if ($this->valuesAreEquivalent($oldValue, $newValue)) {
                    continue;
                }

                $result = $this->updateTaskProperty->execute($task, $property, $newValue, null, $user);

                if ($result->success) {
                    $changes[$property] = [
                        'from' => $result->oldValue,
                        'to' => $result->newValue,
                    ];
                }
            }
        });

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
        TaskUpdatePropertiesRecommendationDto $recommendation,
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

    private function valuesAreEquivalent(mixed $oldValue, mixed $newValue): bool
    {
        if ($oldValue instanceof CarbonInterface && $newValue instanceof CarbonInterface) {
            return $oldValue->eq($newValue);
        }

        return $oldValue === $newValue;
    }

    /**
     * Basic temporal guardrails for schedule/adjust intents using proposed properties.
     * For task intents: only start and duration are applied; validate start + duration does not exceed task due.
     *
     * @param  array<string, mixed>  $properties
     */
    private function isScheduleAcceptableFromProperties(Task $task, array $properties, LlmIntent $intent): bool
    {
        $now = now();

        if (in_array($intent, [LlmIntent::ScheduleTask, LlmIntent::AdjustTaskDeadline], true)) {
            $startRaw = $properties['startDatetime'] ?? null;
            $start = is_string($startRaw) ? \App\Support\DateHelper::parseOptional($startRaw) : null;
            if ($start !== null && $start->lt($now)) {
                return false;
            }
            if ($task->end_datetime !== null && $start !== null) {
                $duration = isset($properties['duration']) && is_numeric($properties['duration'])
                    ? (int) $properties['duration']
                    : null;
                if ($duration !== null && $duration > 0) {
                    $blockEnd = $start->copy()->addMinutes($duration);
                    if ($blockEnd->gt($task->end_datetime->copy()->endOfDay())) {
                        return false;
                    }
                }
            }

            return true;
        }

        $endRaw = $properties['endDatetime'] ?? null;
        $end = is_string($endRaw) ? \App\Support\DateHelper::parseOptional($endRaw) : null;

        if ($end !== null && $end->lt($now)) {
            return false;
        }

        if ($task->end_datetime !== null
            && $end !== null
            && $end->gt($task->end_datetime->copy()->endOfDay())
        ) {
            return false;
        }

        return true;
    }
}
