<?php

namespace App\Actions\Llm;

use App\Actions\Event\UpdateEventPropertyAction;
use App\DataTransferObjects\Llm\EventUpdatePropertiesRecommendationDto;
use App\Enums\ActivityLogAction;
use App\Enums\LlmIntent;
use App\Models\Event;
use App\Models\User;
use App\Services\ActivityLogRecorder;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ApplyEventPropertiesRecommendationAction
{
    /**
     * @var list<string>
     */
    private const ALLOWED_PROPERTIES = [
        'title',
        'description',
        'startDatetime',
        'endDatetime',
        'allDay',
    ];

    public function __construct(
        private UpdateEventPropertyAction $updateEventProperty,
        private ActivityLogRecorder $activityLogRecorder
    ) {}

    /**
     * Apply a generic event property update recommendation to the given event.
     *
     * @param  array<string, mixed>  $overrides  Optional user-modified values for properties.
     */
    public function execute(
        User $user,
        Event $event,
        EventUpdatePropertiesRecommendationDto $recommendation,
        LlmIntent $intent,
        string $userAction,
        array $overrides = []
    ): bool {
        if ($userAction === 'reject') {
            $this->recordAudit($event, $user, $intent, $userAction, $recommendation, []);

            return false;
        }

        $changes = [];

        $properties = array_merge($recommendation->proposedProperties(), $overrides);
        $properties = array_intersect_key($properties, array_flip(self::ALLOWED_PROPERTIES));

        if ($properties === []) {
            $this->recordAudit($event, $user, $intent, $userAction, $recommendation, []);

            return false;
        }

        DB::transaction(function () use (&$changes, $event, $user, $properties): void {
            foreach ($properties as $property => $newValue) {
                $oldValue = $event->getPropertyValueForUpdate($property);

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

        return $changes !== [];
    }

    /**
     * @param  array<string, array{from:mixed,to:mixed}>  $changes
     */
    private function recordAudit(
        Event $event,
        User $user,
        LlmIntent $intent,
        string $userAction,
        EventUpdatePropertiesRecommendationDto $recommendation,
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
