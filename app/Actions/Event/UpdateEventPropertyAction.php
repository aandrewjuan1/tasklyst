<?php

namespace App\Actions\Event;

use App\DataTransferObjects\Event\UpdateEventPropertyResult;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\RecurringEvent;
use App\Models\Tag;
use App\Services\EventService;
use App\Support\DateHelper;
use App\Support\Validation\EventPayloadValidation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

class UpdateEventPropertyAction
{
    public function __construct(
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
        $oldTagIds = $event->tags()->pluck('tags.id')->all();
        $addedIds = array_values(array_diff($validatedValue, $oldTagIds));
        $removedIds = array_values(array_diff($oldTagIds, $validatedValue));
        $addedTagName = count($addedIds) === 1 ? (Tag::find($addedIds[0])?->name ?? null) : null;
        $removedTagName = count($removedIds) === 1 ? (Tag::find($removedIds[0])?->name ?? null) : null;

        try {
            $event->tags()->sync($validatedValue);

            return UpdateEventPropertyResult::success($oldTagIds, $validatedValue, $addedTagName, $removedTagName);
        } catch (\Throwable $e) {
            Log::error('Failed to sync event tags from workspace.', [
                'event_id' => $event->id,
                'exception' => $e,
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

        return UpdateEventPropertyResult::success($oldValue, $newValue);
    }
}
