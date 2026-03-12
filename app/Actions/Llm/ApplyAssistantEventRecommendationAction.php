<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\EventScheduleRecommendationDto;
use App\DataTransferObjects\Llm\EventUpdatePropertiesRecommendationDto;
use App\Enums\LlmIntent;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class ApplyAssistantEventRecommendationAction
{
    public function __construct(
        private ApplyEventPropertiesRecommendationAction $applyEventProperties,
    ) {}

    /**
     * Apply or reject an event recommendation coming from an assistant message snapshot.
     * Returns true if the event was actually updated (or explicitly rejected); false if we skipped.
     *
     * @param  array<string, mixed>  $snapshot  The recommendation_snapshot array from metadata.
     */
    public function execute(User $user, Event $event, array $snapshot, string $userAction): bool
    {
        $intentValue = (string) ($snapshot['intent'] ?? '');

        $intent = LlmIntent::tryFrom($intentValue);
        if (! $intent instanceof LlmIntent) {
            Log::warning('assistant.event_apply.invalid_intent', [
                'user_id' => $user->id,
                'event_id' => $event->id,
                'intent_raw' => $intentValue,
                'user_action' => $userAction,
                'snapshot_keys' => array_keys($snapshot),
            ]);

            return false;
        }

        if (! in_array($intent, [
            LlmIntent::ScheduleEvent,
            LlmIntent::AdjustEventTime,
            LlmIntent::UpdateEventProperties,
            LlmIntent::ScheduleTasksAndEvents,
            LlmIntent::ScheduleEventsAndProjects,
        ], true)) {
            Log::info('assistant.event_apply.intent_not_supported_for_event_apply', [
                'user_id' => $user->id,
                'event_id' => $event->id,
                'intent' => $intent->value,
                'user_action' => $userAction,
            ]);

            return false;
        }

        $rawStructured = $snapshot['structured'] ?? [];
        $structured = is_array($rawStructured) ? $rawStructured : (array) $rawStructured;
        if (isset($structured[0]) && is_array($structured[0])) {
            $structured = $structured[0];
        }
        $rawAppliable = $snapshot['appliable_changes'] ?? $snapshot['appliableChanges'] ?? [];
        $appliable = is_array($rawAppliable) ? $rawAppliable : (array) $rawAppliable;
        $rawProperties = $appliable['properties'] ?? [];
        $properties = is_array($rawProperties) ? $rawProperties : (array) $rawProperties;
        $properties = array_filter($properties, static fn ($key): bool => is_string($key) && $key !== '', ARRAY_FILTER_USE_KEY);

        if ($properties === []) {
            $scheduleDto = EventScheduleRecommendationDto::fromStructured($structured);
            if ($scheduleDto !== null) {
                $properties = $scheduleDto->proposedProperties();
            }
        }

        $hasStartOrEnd = ! empty($structured['start_datetime']) || ! empty($structured['startDatetime'])
            || ! empty($structured['end_datetime']) || ! empty($structured['endDatetime']);
        if ($properties === [] && $hasStartOrEnd) {
            $properties = $this->propertiesFromStructured($structured);
        }

        if ($properties === []) {
            Log::info('assistant.event_apply.no_properties_to_apply', [
                'user_id' => $user->id,
                'event_id' => $event->id,
                'intent' => $intent->value,
                'user_action' => $userAction,
                'structured_keys' => array_keys($structured),
                'appliable_changes_keys' => is_array($appliable) ? array_keys($appliable) : [],
            ]);
        }

        $reasoning = trim((string) ($snapshot['reasoning'] ?? $structured['reasoning'] ?? ''));
        if ($reasoning === '') {
            $reasoning = 'Schedule suggested by assistant.';
        }

        $confidence = (float) (
            $snapshot['validation_confidence']
            ?? $snapshot['validationConfidence']
            ?? $structured['validation_confidence']
            ?? $structured['validationConfidence']
            ?? 0.0
        );
        $dtoStructured = [
            'reasoning' => $reasoning,
            'confidence' => $confidence,
            'properties' => $properties,
        ];

        $dto = EventUpdatePropertiesRecommendationDto::fromStructured($dtoStructured);
        if ($dto === null) {
            Log::warning('assistant.event_apply.dto_null', [
                'user_id' => $user->id,
                'event_id' => $event->id,
                'intent' => $intent->value,
                'user_action' => $userAction,
                'properties' => $properties,
            ]);

            return false;
        }

        if ($dto->properties === [] && $userAction === 'accept') {
            Log::info('assistant.event_apply.empty_properties_on_accept', [
                'user_id' => $user->id,
                'event_id' => $event->id,
                'intent' => $intent->value,
                'user_action' => $userAction,
                'reasoning' => $dto->reasoning,
                'confidence' => $dto->confidence,
            ]);

            return false;
        }

        $didUpdate = $this->applyEventProperties->execute(
            user: $user,
            event: $event,
            recommendation: $dto,
            intent: $intent,
            userAction: $userAction,
        );

        Log::info('assistant.event_apply.success', [
            'user_id' => $user->id,
            'event_id' => $event->id,
            'intent' => $intent->value,
            'user_action' => $userAction,
            'applied_properties' => $dto->properties,
            'confidence' => $dto->confidence,
        ]);

        return $didUpdate;
    }

    /**
     * Build appliable properties array from structured when appliable_changes.properties is missing or empty.
     *
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    private function propertiesFromStructured(array $structured): array
    {
        $props = [];
        $start = $structured['start_datetime'] ?? $structured['startDatetime'] ?? null;
        if (is_string($start) && trim($start) !== '') {
            $props['startDatetime'] = trim($start);
        }
        $end = $structured['end_datetime'] ?? $structured['endDatetime'] ?? null;
        if (is_string($end) && trim($end) !== '') {
            $props['endDatetime'] = trim($end);
        }

        return $props;
    }
}
