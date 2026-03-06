<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\EventCreateRecommendationDto;
use App\Enums\LlmIntent;
use App\Models\User;

class ApplyAssistantEventCreateRecommendationAction
{
    public function __construct(
        private ApplyEventCreateRecommendationAction $applyEventCreate,
    ) {}

    /**
     * Apply or reject an event create recommendation coming from an assistant message snapshot.
     *
     * @param  array<string, mixed>  $snapshot  The recommendation_snapshot array from metadata.
     */
    public function execute(User $user, array $snapshot, string $userAction): void
    {
        $intentValue = (string) ($snapshot['intent'] ?? '');

        $intent = LlmIntent::tryFrom($intentValue);
        if (! $intent instanceof LlmIntent || $intent !== LlmIntent::CreateEvent) {
            return;
        }

        $structured = (array) ($snapshot['structured'] ?? []);

        $dto = EventCreateRecommendationDto::fromStructured($structured);
        if ($dto === null) {
            return;
        }

        $this->applyEventCreate->execute(
            user: $user,
            recommendation: $dto,
            intent: $intent,
            userAction: $userAction,
        );
    }
}
