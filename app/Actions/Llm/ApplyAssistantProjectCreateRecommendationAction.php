<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\ProjectCreateRecommendationDto;
use App\Enums\LlmIntent;
use App\Models\User;

class ApplyAssistantProjectCreateRecommendationAction
{
    public function __construct(
        private ApplyProjectCreateRecommendationAction $applyProjectCreate,
    ) {}

    /**
     * Apply or reject a project create recommendation coming from an assistant message snapshot.
     *
     * @param  array<string, mixed>  $snapshot  The recommendation_snapshot array from metadata.
     */
    public function execute(User $user, array $snapshot, string $userAction): void
    {
        $intentValue = (string) ($snapshot['intent'] ?? '');

        $intent = LlmIntent::tryFrom($intentValue);
        if (! $intent instanceof LlmIntent || $intent !== LlmIntent::CreateProject) {
            return;
        }

        $structured = (array) ($snapshot['structured'] ?? []);

        $dto = ProjectCreateRecommendationDto::fromStructured($structured);
        if ($dto === null) {
            return;
        }

        $this->applyProjectCreate->execute(
            user: $user,
            recommendation: $dto,
            intent: $intent,
            userAction: $userAction,
        );
    }
}
