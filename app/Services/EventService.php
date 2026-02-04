<?php

namespace App\Services;

use App\Enums\EventRecurrenceType;
use App\Models\Event;
use App\Models\RecurringEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EventService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createEvent(User $user, array $attributes): Event
    {
        return DB::transaction(function () use ($user, $attributes): Event {
            $tagIds = $attributes['tagIds'] ?? [];
            unset($attributes['tagIds']);

            $recurrenceData = $attributes['recurrence'] ?? null;
            unset($attributes['recurrence']);

            $event = Event::query()->create([
                ...$attributes,
                'user_id' => $user->id,
            ]);

            if (! empty($tagIds)) {
                $event->tags()->attach($tagIds);
            }

            if ($recurrenceData !== null && ($recurrenceData['enabled'] ?? false)) {
                $this->createRecurringEvent($event, $recurrenceData);
            }

            return $event;
        });
    }

    /**
     * Update or create RecurringEvent for the given event based on recurrence data.
     * If enabled is false, deletes existing RecurringEvent. If enabled is true and type is set, creates or updates.
     *
     * @param  array<string, mixed>  $recurrenceData
     */
    public function updateOrCreateRecurringEvent(Event $event, array $recurrenceData): void
    {
        DB::transaction(function () use ($event, $recurrenceData): void {
            $event->recurringEvent?->delete();

            if (($recurrenceData['enabled'] ?? false) && ($recurrenceData['type'] ?? null) !== null) {
                $this->createRecurringEvent($event, $recurrenceData);
            }
        });
    }

    /**
     * Create a RecurringEvent record for the given event.
     *
     * @param  array<string, mixed>  $recurrenceData
     */
    private function createRecurringEvent(Event $event, array $recurrenceData): void
    {
        $recurrenceType = $recurrenceData['type'] ?? null;
        if ($recurrenceType === null) {
            return;
        }

        $recurrenceTypeEnum = EventRecurrenceType::from($recurrenceType);
        $interval = max(1, (int) ($recurrenceData['interval'] ?? 1));
        $daysOfWeek = $recurrenceData['daysOfWeek'] ?? [];

        $startDatetime = $event->start_datetime;
        $endDatetime = $event->end_datetime;

        if ($startDatetime === null) {
            $startDatetime = Carbon::now();
        }

        $daysOfWeekString = null;
        if (is_array($daysOfWeek) && ! empty($daysOfWeek)) {
            $daysOfWeekString = json_encode($daysOfWeek, JSON_THROW_ON_ERROR);
        }

        RecurringEvent::query()->create([
            'event_id' => $event->id,
            'recurrence_type' => $recurrenceTypeEnum,
            'interval' => $interval,
            'days_of_week' => $daysOfWeekString,
            'start_datetime' => $startDatetime,
            'end_datetime' => $endDatetime,
            'timezone' => config('app.timezone'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateEvent(Event $event, array $attributes): Event
    {
        unset($attributes['user_id']);

        return DB::transaction(function () use ($event, $attributes): Event {
            $event->fill($attributes);
            $event->save();

            return $event;
        });
    }

    public function deleteEvent(Event $event): bool
    {
        return DB::transaction(function () use ($event): bool {
            return (bool) $event->delete();
        });
    }
}
