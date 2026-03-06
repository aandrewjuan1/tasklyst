<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\ProjectUpdatePropertiesRecommendationDto;
use App\Enums\LlmIntent;
use App\Models\Project;
use App\Models\User;

class ApplyAssistantProjectPropertiesRecommendationAction
{
    public function __construct(
        private ApplyProjectPropertiesRecommendationAction $applyProjectProperties,
    ) {}

    /**
     * Apply or reject a project properties recommendation coming from an assistant message snapshot.
     *
     * @param  array<string, mixed>  $snapshot  The recommendation_snapshot array from metadata.
     */
    public function execute(User $user, Project $project, array $snapshot, string $userAction): void
    {
        $intentValue = (string) ($snapshot['intent'] ?? '');

        $intent = LlmIntent::tryFrom($intentValue);
        if (! $intent instanceof LlmIntent || $intent !== LlmIntent::UpdateProjectProperties) {
            return;
        }

        $structured = (array) ($snapshot['structured'] ?? []);

        $dto = ProjectUpdatePropertiesRecommendationDto::fromStructured($structured);
        if ($dto === null) {
            return;
        }

        $this->applyProjectProperties->execute(
            user: $user,
            project: $project,
            recommendation: $dto,
            intent: $intent,
            userAction: $userAction,
        );
    }
}
