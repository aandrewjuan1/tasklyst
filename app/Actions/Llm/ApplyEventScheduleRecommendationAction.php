<?php

namespace App\Actions\Llm;

use App\Actions\Event\UpdateEventPropertyAction;
use App\DataTransferObjects\Llm\EventScheduleRecommendationDto;
use App\Enums\ActivityLogAction;
use App\Enums\LlmIntent;
use App\Models\Event;
use App\Models\User;
use App\Services\ActivityLogRecorder;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

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

        DB::transaction(function () use (&$changes, $event, $user, $attributes): void {
            foreach (['startDatetime', 'endDatetime'] as $property) {
                if (! array_key_exists($property, $attributes)) {
                    continue;
                }

                $oldValue = $event->getPropertyValueForUpdate($property);
                $newValue = $attributes[$property];

                if ($this->valuesAreEquivalent($oldValue, $newValue)) {
                    continue;
                }

                $result = $this->updateEventProperty->execute($event, $property, $newValue, null, $user);

                if ($result->success) {
                    $changes[$property] = [
                        'from' => $result->oldValue,
                        'to' => $result->newValue,
                    ];
                }
            }
        });

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
                    'entity_type' => 'event',
                    'user_action' => $userAction,
                    'reasoning' => $recommendation->reasoning,
                    'changes' => $changes,
                    'modified_fields' => array_keys($changes),
                ],
            ]
        );
    }

    private function valuesAreEquivalent(mixed $oldValue, mixed $newValue): bool
    {
        if ($oldValue instanceof CarbonInterface && $newValue instanceof CarbonInterface) {
            return $oldValue->eq($newValue);
        }

        return $oldValue === $newValue;
    }
}
