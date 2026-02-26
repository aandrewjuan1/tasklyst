<?php

namespace App\Actions\Llm;

use App\Actions\Event\UpdateEventPropertyAction;
use App\DataTransferObjects\Llm\EventScheduleRecommendationDto;
use App\Enums\ActivityLogAction;
use App\Enums\LlmIntent;
use App\Models\Event;
use App\Models\User;
use App\Services\ActivityLogRecorder;

class ApplyEventScheduleRecommendationAction
{
    public function __construct(
        private UpdateEventPropertyAction $updateEventProperty,
        private ActivityLogRecorder $activityLogRecorder
    ) {}

    /**
     * Apply an event scheduling or time-adjustment recommendation to the given event.
     *
     * @param  array<string, mixed>  $overrides  Optional user-modified values for startDatetime, endDatetime.
     */
    public function execute(
        User $user,
        Event $event,
        EventScheduleRecommendationDto $recommendation,
        LlmIntent $intent,
        string $userAction,
        array $overrides = []
    ): void {
        if ($userAction === 'reject') {
            $this->recordAudit($event, $user, $intent, $userAction, $recommendation, []);

            return;
        }

        $changes = [];

        $attributes = $recommendation->toEventAttributes();
        $attributes = array_merge($attributes, $overrides);

        foreach (['startDatetime', 'endDatetime'] as $property) {
            if (! array_key_exists($property, $attributes)) {
                continue;
            }

            $oldValue = $event->getPropertyValueForUpdate($property);
            $newValue = $attributes[$property];

            if ($oldValue === $newValue) {
                continue;
            }

            $this->updateEventProperty->execute($event, $property, $newValue);

            $changes[$property] = [
                'from' => $oldValue,
                'to' => $newValue,
            ];
        }

        $this->recordAudit($event, $user, $intent, $userAction, $recommendation, $changes);
    }

    /**
     * @param  array<string, array{from:mixed,to:mixed}>  $changes
     */
    private function recordAudit(
        Event $event,
        User $user,
        LlmIntent $intent,
        string $userAction,
        EventScheduleRecommendationDto $recommendation,
        array $changes
    ): void {
        $this->activityLogRecorder->record(
            $event,
            $user,
            ActivityLogAction::FieldUpdated,
            [
                'field' => 'llm_recommendation',
                'from' => null,
                'to' => [
                    'intent' => $intent->value,
                    'user_action' => $userAction,
                    'reasoning' => $recommendation->reasoning,
                    'changes' => $changes,
                ],
            ]
        );
    }
}
