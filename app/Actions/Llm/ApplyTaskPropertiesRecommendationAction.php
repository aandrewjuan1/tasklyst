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
    ];

    public function __construct(
        private UpdateTaskPropertyAction $updateTaskProperty,
        private ActivityLogRecorder $activityLogRecorder
    ) {}

    /**
     * Apply a task property update recommendation to the given task.
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
    ): bool {
        if ($userAction === 'reject') {
            $this->recordAudit($task, $user, $intent, $userAction, $recommendation, []);

            return false;
        }

        $changes = [];

        $properties = array_merge($recommendation->proposedProperties(), $overrides);
        $properties = array_intersect_key($properties, array_flip(self::ALLOWED_PROPERTIES));

        if ($properties === []) {
            $this->recordAudit($task, $user, $intent, $userAction, $recommendation, []);

            return false;
        }

        DB::transaction(function () use (&$changes, $task, $user, $properties): void {
            foreach ($properties as $property => $newValue) {
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

        return $changes !== [];
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
}
