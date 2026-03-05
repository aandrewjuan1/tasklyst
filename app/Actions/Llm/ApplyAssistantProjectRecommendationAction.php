<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\ProjectScheduleRecommendationDto;
use App\Enums\LlmIntent;
use App\Models\Project;
use App\Models\User;

class ApplyAssistantProjectRecommendationAction
{
    public function __construct(
        private ApplyProjectScheduleRecommendationAction $applyProjectSchedule,
    ) {}

    /**
     * Apply or reject a project recommendation coming from an assistant message snapshot.
     *
     * @param  array<string, mixed>  $snapshot  The recommendation_snapshot array from metadata.
     */
    public function execute(User $user, Project $project, array $snapshot, string $userAction): void
    {
        $intentValue = (string) ($snapshot['intent'] ?? '');

        $intent = LlmIntent::tryFrom($intentValue);
        if (! $intent instanceof LlmIntent) {
            return;
        }

        $structured = (array) ($snapshot['structured'] ?? []);

        $dto = ProjectScheduleRecommendationDto::fromStructured($structured);
        if ($dto === null) {
            return;
        }

        $this->applyProjectSchedule->execute(
            user: $user,
            project: $project,
            recommendation: $dto,
            intent: $intent,
            userAction: $userAction,
        );
    }
}
