<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\EventScheduleRecommendationDto;
use App\Enums\LlmIntent;
use App\Models\Event;
use App\Models\User;

class ApplyAssistantEventRecommendationAction
{
    public function __construct(
        private ApplyEventScheduleRecommendationAction $applyEventSchedule,
    ) {}

    /**
     * Apply or reject an event recommendation coming from an assistant message snapshot.
     *
     * @param  array<string, mixed>  $snapshot  The recommendation_snapshot array from metadata.
     */
    public function execute(User $user, Event $event, array $snapshot, string $userAction): void
    {
        $intentValue = (string) ($snapshot['intent'] ?? '');

        $intent = LlmIntent::tryFrom($intentValue);
        if (! $intent instanceof LlmIntent) {
            return;
        }

        $structured = (array) ($snapshot['structured'] ?? []);

        $dto = EventScheduleRecommendationDto::fromStructured($structured);
        if ($dto === null) {
            return;
        }

        $this->applyEventSchedule->execute(
            user: $user,
            event: $event,
            recommendation: $dto,
            intent: $intent,
            userAction: $userAction,
        );
    }
}
