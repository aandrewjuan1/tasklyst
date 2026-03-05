<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\TaskScheduleRecommendationDto;
use App\Enums\LlmIntent;
use App\Models\Task;
use App\Models\User;

class ApplyAssistantTaskRecommendationAction
{
    public function __construct(
        private ApplyTaskScheduleRecommendationAction $applyTaskSchedule,
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

        $structured = (array) ($snapshot['structured'] ?? []);

        $dto = TaskScheduleRecommendationDto::fromStructured($structured);
        if ($dto === null) {
            return;
        }

        $this->applyTaskSchedule->execute(
            user: $user,
            task: $task,
            recommendation: $dto,
            intent: $intent,
            userAction: $userAction,
        );
    }
}
