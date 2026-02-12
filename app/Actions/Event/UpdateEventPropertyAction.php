<?php

namespace App\Actions\Event;

use App\DataTransferObjects\Event\UpdateEventPropertyResult;
use App\Enums\ActivityLogAction;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\RecurringEvent;
use App\Models\Tag;
use App\Services\ActivityLogRecorder;
use App\Services\EventService;
use App\Support\DateHelper;
use App\Support\Validation\EventPayloadValidation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

class UpdateEventPropertyAction
{
    public function __construct(
        private ActivityLogRecorder $activityLogRecorder,
        private EventService $eventService
    ) {}

    public function execute(Event $event, string $property, mixed $validatedValue, ?string $occurrenceDate = null): UpdateEventPropertyResult
    {
        if ($property === 'tagIds') {
            return $this->updateTagIds($event, $validatedValue);
        }

        if ($property === 'recurrence') {
            return $this->updateRecurrence($event, $validatedValue);
        }

        if ($property === 'status') {
            $recurringEvent = $event->recurringEvent ?? RecurringEvent::where('event_id', $event->id)->first();
            if ($recurringEvent !== null) {
                return $this->updateRecurringStatus($event, $validatedValue, $occurrenceDate);
            }
        }

        return $this->updateSimpleProperty($event, $property, $validatedValue);
    }

    private function updateTagIds(Event $event, mixed $validatedValue): UpdateEventPropertyResult
    {
        Log::info('[TAG-SYNC] Starting event tag update', [
            'event_id' => $event->id,
            'event_title' => $event->title,
            'validated_value' => $validatedValue,
            'validated_value_type' => gettype($validatedValue),
            'validated_value_is_array' => is_array($validatedValue),
        ]);

        $oldTagIds = $event->tags()->pluck('tags.id')->all();

        Log::info('[TAG-SYNC] Retrieved current event tags', [
            'event_id' => $event->id,
            'old_tag_ids' => $oldTagIds,
            'old_tag_ids_count' => count($oldTagIds),
        ]);

        $addedIds = array_values(array_diff($validatedValue, $oldTagIds));
        $removedIds = array_values(array_diff($oldTagIds, $validatedValue));

        Log::info('[TAG-SYNC] Calculated tag changes', [
            'event_id' => $event->id,
            'added_ids' => $addedIds,
            'removed_ids' => $removedIds,
            'added_count' => count($addedIds),
            'removed_count' => count($removedIds),
        ]);

        $addedTagName = count($addedIds) === 1 ? (Tag::find($addedIds[0])?->name ?? null) : null;
        $removedTagName = count($removedIds) === 1 ? (Tag::find($removedIds[0])?->name ?? null) : null;

        if ($addedTagName || $removedTagName) {
            Log::info('[TAG-SYNC] Tag names for toast', [
                'event_id' => $event->id,
                'added_tag_name' => $addedTagName,
                'removed_tag_name' => $removedTagName,
            ]);
        }

        try {
            Log::info('[TAG-SYNC] About to sync tags', [
                'event_id' => $event->id,
                'sync_ids' => $validatedValue,
            ]);

            $event->tags()->sync($validatedValue);

            Log::info('[TAG-SYNC] Successfully synced event tags', [
                'event_id' => $event->id,
                'old_tag_ids' => $oldTagIds,
                'new_tag_ids' => $validatedValue,
            ]);

            $this->activityLogRecorder->record(
                $event,
                auth()->user(),
                ActivityLogAction::FieldUpdated,
                ['field' => 'tagIds', 'from' => $oldTagIds, 'to' => $validatedValue]
            );

            return UpdateEventPropertyResult::success($oldTagIds, $validatedValue, $addedTagName, $removedTagName);
        } catch (\Throwable $e) {
            Log::error('[TAG-SYNC] Failed to sync event tags from workspace', [
                'event_id' => $event->id,
                'old_tag_ids' => $oldTagIds,
                'new_tag_ids' => $validatedValue,
                'exception' => $e->getMessage(),
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return UpdateEventPropertyResult::failure($oldTagIds, $validatedValue);
        }
    }

    private function updateRecurrence(Event $event, mixed $validatedValue): UpdateEventPropertyResult
    {
        $event->loadMissing('recurringEvent');
        $oldRecurrence = RecurringEvent::toPayloadArray($event->recurringEvent);

        try {
            $this->eventService->updateOrCreateRecurringEvent($event, $validatedValue);

            $this->activityLogRecorder->record(
                $event,
                auth()->user(),
                ActivityLogAction::FieldUpdated,
                ['field' => 'recurrence', 'from' => $oldRecurrence, 'to' => $validatedValue]
            );

            return UpdateEventPropertyResult::success($oldRecurrence, $validatedValue);
        } catch (\Throwable $e) {
            Log::error('Failed to update event recurrence from workspace.', [
                'event_id' => $event->id,
                'exception' => $e,
            ]);

            return UpdateEventPropertyResult::failure($oldRecurrence, $validatedValue);
        }
    }

    private function updateRecurringStatus(Event $event, mixed $validatedValue, ?string $occurrenceDate): UpdateEventPropertyResult
    {
        $recurringEvent = $event->recurringEvent ?? RecurringEvent::where('event_id', $event->id)->first();
        if ($recurringEvent === null) {
            return UpdateEventPropertyResult::failure($event->status?->value, $validatedValue);
        }

        $event->setRelation('recurringEvent', $recurringEvent);
        $oldStatus = $event->status?->value;
        $statusEnum = EventStatus::tryFrom($validatedValue) ?? $event->status;

        try {
            if ($occurrenceDate !== null && $occurrenceDate !== '') {
                $this->eventService->updateRecurringOccurrenceStatus($event, Date::parse($occurrenceDate), $statusEnum);
            } else {
                $this->eventService->updateEvent($event, ['status' => $validatedValue]);
            }

            $this->activityLogRecorder->record(
                $event,
                auth()->user(),
                ActivityLogAction::FieldUpdated,
                ['field' => 'status', 'from' => $oldStatus, 'to' => $validatedValue]
            );

            return UpdateEventPropertyResult::success($oldStatus, $validatedValue);
        } catch (\Throwable $e) {
            Log::error('Failed to update recurring event status from workspace.', [
                'event_id' => $event->id,
                'exception' => $e,
            ]);

            return UpdateEventPropertyResult::failure($event->status?->value, $validatedValue);
        }
    }

    private function updateSimpleProperty(Event $event, string $property, mixed $validatedValue): UpdateEventPropertyResult
    {
        $column = Event::propertyToColumn($property);
        $oldValue = $event->getPropertyValueForUpdate($property);

        $attributes = [$column => $validatedValue];
        if ($column === 'start_datetime' || $column === 'end_datetime') {
            $parsedDatetime = DateHelper::parseOptional($validatedValue);
            $attributes[$column] = $parsedDatetime;

            $start = $column === 'start_datetime' ? $parsedDatetime : $event->start_datetime;
            $end = $column === 'end_datetime' ? $parsedDatetime : $event->end_datetime;

            $dateRangeError = EventPayloadValidation::validateEventDateRangeForUpdate($start, $end);
            if ($dateRangeError !== null) {
                return UpdateEventPropertyResult::failure($oldValue, $validatedValue, $dateRangeError);
            }
        }

        try {
            $this->eventService->updateEvent($event, $attributes);
        } catch (\Throwable $e) {
            Log::error('Failed to update event property from workspace.', [
                'event_id' => $event->id,
                'property' => $property,
                'exception' => $e,
            ]);

            return UpdateEventPropertyResult::failure($oldValue, $validatedValue);
        }

        $newValue = in_array($property, ['startDatetime', 'endDatetime'], true) ? ($attributes[$column] ?? null) : $validatedValue;

        $this->activityLogRecorder->record(
            $event,
            auth()->user(),
            ActivityLogAction::FieldUpdated,
            ['field' => $property, 'from' => $oldValue, 'to' => $newValue]
        );

        return UpdateEventPropertyResult::success($oldValue, $newValue);
    }
}
