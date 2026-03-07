<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\EventUpdatePropertiesRecommendationDto;
use App\Enums\LlmIntent;
use App\Models\Event;
use App\Models\User;

class ApplyAssistantEventRecommendationAction
{
    public function __construct(
        private ApplyEventPropertiesRecommendationAction $applyEventProperties,
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

        if (! in_array($intent, [LlmIntent::ScheduleEvent, LlmIntent::AdjustEventTime], true)) {
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

        $dto = EventUpdatePropertiesRecommendationDto::fromStructured($dtoStructured);
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
