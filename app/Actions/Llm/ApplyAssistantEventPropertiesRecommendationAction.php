<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\EventUpdatePropertiesRecommendationDto;
use App\Enums\LlmIntent;
use App\Models\Event;
use App\Models\User;

class ApplyAssistantEventPropertiesRecommendationAction
{
    public function __construct(
        private ApplyEventPropertiesRecommendationAction $applyEventProperties,
    ) {}

    /**
     * Apply or reject an event properties recommendation coming from an assistant message snapshot.
     *
     * @param  array<string, mixed>  $snapshot  The recommendation_snapshot array from metadata.
     */
    public function execute(User $user, Event $event, array $snapshot, string $userAction): void
    {
        $intentValue = (string) ($snapshot['intent'] ?? '');

        $intent = LlmIntent::tryFrom($intentValue);
        if (! $intent instanceof LlmIntent || $intent !== LlmIntent::UpdateEventProperties) {
            return;
        }

        $structured = (array) ($snapshot['structured'] ?? []);

        $dto = EventUpdatePropertiesRecommendationDto::fromStructured($structured);
        if ($dto === null) {
            return;
        }

        $this->applyEventProperties->execute(
            user: $user,
            event: $event,
            recommendation: $dto,
            intent: $intent,
            userAction: $userAction,
        );
    }
}
