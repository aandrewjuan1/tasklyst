<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\ProjectUpdatePropertiesRecommendationDto;
use App\Enums\LlmIntent;
use App\Models\Project;
use App\Models\User;

class ApplyAssistantProjectRecommendationAction
{
    public function __construct(
        private ApplyProjectPropertiesRecommendationAction $applyProjectProperties,
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

        if (! in_array($intent, [LlmIntent::ScheduleProject, LlmIntent::AdjustProjectTimeline], true)) {
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

        $dto = ProjectUpdatePropertiesRecommendationDto::fromStructured($dtoStructured);
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
