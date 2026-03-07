<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\TaskUpdatePropertiesRecommendationDto;
use App\Enums\LlmIntent;
use App\Models\Task;
use App\Models\User;

class ApplyAssistantTaskRecommendationAction
{
    public function __construct(
        private ApplyTaskPropertiesRecommendationAction $applyTaskProperties,
    ) {}

    /**
     * Apply or reject a task recommendation coming from an assistant message snapshot.
     *
     * @param  array<string, mixed>  $snapshot  The recommendation_snapshot array from metadata.
     */
    public function execute(User $user, Task $task, array $snapshot, string $userAction): void
    {
        $intentValue = (string) ($snapshot['intent'] ?? '');

        $intent = LlmIntent::tryFrom($intentValue);
        if (! $intent instanceof LlmIntent) {
            return;
        }

        if (! in_array($intent, [
            LlmIntent::ScheduleTask,
            LlmIntent::AdjustTaskDeadline,
            LlmIntent::ScheduleTasksAndEvents,
            LlmIntent::ScheduleTasksAndProjects,
        ], true)) {
            return;
        }

        $structured = (array) ($snapshot['structured'] ?? []);
        $appliable = (array) ($snapshot['appliable_changes'] ?? []);
        $properties = isset($appliable['properties']) && is_array($appliable['properties'])
            ? $appliable['properties']
            : [];

        $reasoning = trim((string) ($snapshot['reasoning'] ?? $structured['reasoning'] ?? ''));
        if ($reasoning === '') {
            $reasoning = 'Schedule suggested by assistant.';
        }

        $dtoStructured = [
            'reasoning' => $reasoning,
            'confidence' => (float) ($snapshot['validation_confidence'] ?? $structured['validation_confidence'] ?? 0.0),
            'properties' => $properties,
        ];

        $dto = TaskUpdatePropertiesRecommendationDto::fromStructured($dtoStructured);
        if ($dto === null) {
            return;
        }

        $this->applyTaskProperties->execute(
            user: $user,
            task: $task,
            recommendation: $dto,
            intent: $intent,
            userAction: $userAction,
        );
    }
}
