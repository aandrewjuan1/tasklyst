<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\TaskUpdatePropertiesRecommendationDto;
use App\Enums\LlmIntent;
use App\Models\Task;
use App\Models\User;

class ApplyAssistantTaskPropertiesRecommendationAction
{
    public function __construct(
        private ApplyTaskPropertiesRecommendationAction $applyTaskProperties,
    ) {}

    /**
     * Apply or reject a task properties recommendation coming from an assistant message snapshot.
     *
     * @param  array<string, mixed>  $snapshot  The recommendation_snapshot array from metadata.
     */
    public function execute(User $user, Task $task, array $snapshot, string $userAction): void
    {
        $intentValue = (string) ($snapshot['intent'] ?? '');

        $intent = LlmIntent::tryFrom($intentValue);
        if (! $intent instanceof LlmIntent || $intent !== LlmIntent::UpdateTaskProperties) {
            return;
        }

        $structured = (array) ($snapshot['structured'] ?? []);

        $dto = TaskUpdatePropertiesRecommendationDto::fromStructured($structured);
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
