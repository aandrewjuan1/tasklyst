<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\TaskCreateRecommendationDto;
use App\Enums\LlmIntent;
use App\Models\User;

class ApplyAssistantTaskCreateRecommendationAction
{
    public function __construct(
        private ApplyTaskCreateRecommendationAction $applyTaskCreate,
    ) {}

    /**
     * Apply or reject a task create recommendation coming from an assistant message snapshot.
     *
     * @param  array<string, mixed>  $snapshot  The recommendation_snapshot array from metadata.
     */
    public function execute(User $user, array $snapshot, string $userAction): void
    {
        $intentValue = (string) ($snapshot['intent'] ?? '');

        $intent = LlmIntent::tryFrom($intentValue);
        if (! $intent instanceof LlmIntent || $intent !== LlmIntent::CreateTask) {
            return;
        }

        $structured = (array) ($snapshot['structured'] ?? []);

        $dto = TaskCreateRecommendationDto::fromStructured($structured);
        if ($dto === null) {
            return;
        }

        $this->applyTaskCreate->execute(
            user: $user,
            recommendation: $dto,
            intent: $intent,
            userAction: $userAction,
        );
    }
}
