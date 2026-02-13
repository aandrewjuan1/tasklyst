<?php

namespace App\Services;

use App\Enums\ActivityLogAction;
use App\Enums\EventRecurrenceType;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventException;
use App\Models\EventInstance;
use App\Models\RecurringEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EventService
{
    public function __construct(
        private ActivityLogRecorder $activityLogRecorder,
        private RecurrenceExpander $recurrenceExpander
    ) {}

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

            $this->activityLogRecorder->record($event, $user, ActivityLogAction::ItemCreated, [
                'title' => $event->title,
            ]);

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

        // Use event's start_datetime and end_datetime. When both are null, the item is treated as "always relevant" in the list.
        $startDatetime = $event->start_datetime;
        $endDatetime = $event->end_datetime;

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
        ]);

        $this->normalizeBaseStatusForRecurringEvent($event);
    }

    /**
     * When enabling recurrence with a non-default status (e.g. Ongoing, Completed, Tentative), create an instance
     * for today to preserve that status, and reset base status to Scheduled for future occurrences.
     */
    private function normalizeBaseStatusForRecurringEvent(Event $event): void
    {
        $event->load('recurringEvent');
        if ($event->recurringEvent === null) {
            return;
        }

        if ($event->status === null || $event->status === EventStatus::Scheduled) {
            return;
        }

        $today = now()->toDateString();
        $startOfDay = now()->copy()->startOfDay();
        $endOfDay = now()->copy()->endOfDay();
        $occurrences = $this->recurrenceExpander->expand($event->recurringEvent, $startOfDay, $endOfDay);
        if (collect($occurrences)->contains(fn ($d) => $d->format('Y-m-d') === $today)) {
            $this->updateRecurringOccurrenceStatus($event, now(), $event->status);
        }

        $event->update(['status' => EventStatus::Scheduled]);
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

            $this->syncRecurringEventDatesIfNeeded($event, $attributes);

            return $event;
        });
    }

    public function deleteEvent(Event $event, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($event, $actor): bool {
            $this->activityLogRecorder->record($event, $actor, ActivityLogAction::ItemDeleted, [
                'title' => $event->title,
            ]);

            return (bool) $event->delete();
        });
    }

    public function restoreEvent(Event $event, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($event, $actor): bool {
            $this->activityLogRecorder->record($event, $actor, ActivityLogAction::ItemRestored, [
                'title' => $event->title,
            ]);

            return (bool) $event->restore();
        });
    }

    public function forceDeleteEvent(Event $event, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($event, $actor): bool {
            $this->activityLogRecorder->record($event, $actor, ActivityLogAction::ItemDeleted, [
                'title' => $event->title,
            ]);

            return (bool) $event->forceDelete();
        });
    }

    /**
     * Create or update an EventInstance for the given recurring event occurrence date with any status.
     * Does not modify the parent Event.
     */
    public function updateRecurringOccurrenceStatus(Event $event, CarbonInterface $date, EventStatus $status): EventInstance
    {
        $recurringEvent = $event->recurringEvent;
        if ($recurringEvent === null) {
            throw new \InvalidArgumentException('Event must have a recurring event to update an occurrence status.');
        }

        $instanceDate = $date instanceof \DateTimeInterface
            ? $date->format('Y-m-d')
            : \Carbon\Carbon::parse($date)->format('Y-m-d');

        $instance = EventInstance::query()
            ->where('recurring_event_id', $recurringEvent->id)
            ->whereDate('instance_date', $instanceDate)
            ->first();

        $attributes = [
            'event_id' => $event->id,
            'status' => $status,
            'cancelled' => $status === EventStatus::Cancelled,
            'completed_at' => $status === EventStatus::Completed ? now() : null,
        ];

        if ($instance !== null) {
            $instance->update($attributes);

            return $instance->fresh();
        }

        return EventInstance::query()->create([
            'recurring_event_id' => $recurringEvent->id,
            'event_id' => $event->id,
            'instance_date' => $instanceDate,
            'status' => $status,
            'cancelled' => $status === EventStatus::Cancelled,
            'completed_at' => $status === EventStatus::Completed ? now() : null,
        ]);
    }

    /**
     * Create or update an EventInstance for the given recurring event occurrence date.
     * Marks the occurrence as completed. Does not modify the parent Event.
     */
    public function completeRecurringOccurrence(Event $event, CarbonInterface $date): EventInstance
    {
        return $this->updateRecurringOccurrenceStatus($event, $date, EventStatus::Completed);
    }

    /**
     * Get the effective status for an event on a given date.
     * For recurring events: returns instance status when instanceForDate is set (lazy instance), else base event status.
     * For non-recurring: returns base event status.
     */
    public function getEffectiveStatusForDate(Event $event, CarbonInterface $date): EventStatus
    {
        $instance = $event->instanceForDate ?? null;

        if ($instance instanceof EventInstance) {
            return $instance->status ?? EventStatus::Scheduled;
        }

        return $event->status ?? EventStatus::Scheduled;
    }

    /**
     * Process recurring events for a given date: filter by relevant occurrences,
     * batch-load EventInstance records, and set instanceForDate and effectiveStatusForDate on each event.
     *
     * @param  Collection<int, Event>  $events  Events with recurringEvent relation loaded
     * @return Collection<int, Event>
     */
    public function processRecurringEventsForDate(Collection $events, CarbonInterface $date): Collection
    {
        $recurringEvents = $events->pluck('recurringEvent')->filter();
        $relevantIds = $recurringEvents->isNotEmpty()
            ? $this->recurrenceExpander->getRelevantRecurringIdsForDate(collect(), $recurringEvents, $date)
            : ['task_ids' => [], 'event_ids' => []];

        $relevantEventIds = array_flip($relevantIds['event_ids']);

        $filteredEvents = $events
            ->filter(function (Event $event) use ($relevantEventIds): bool {
                if ($event->recurringEvent === null) {
                    return true;
                }

                return isset($relevantEventIds[$event->recurringEvent->id]);
            })
            ->values();

        $recurringEventIds = $filteredEvents->pluck('recurringEvent.id')->filter()->values();
        $instancesByRecurringId = $recurringEventIds->isNotEmpty()
            ? EventInstance::query()
                ->whereIn('recurring_event_id', $recurringEventIds)
                ->whereDate('instance_date', $date)
                ->get()
                ->keyBy('recurring_event_id')
            : collect();

        return $filteredEvents
            ->map(function (Event $event) use ($date, $instancesByRecurringId): Event {
                if ($event->recurringEvent !== null) {
                    $event->instanceForDate = $instancesByRecurringId->get($event->recurringEvent->id);
                }
                $event->effectiveStatusForDate = $this->getEffectiveStatusForDate($event, $date);

                return $event;
            })
            ->values();
    }

    /**
     * Check if an event is relevant for the given date (should appear in workspace).
     * For recurring events: date must be in expanded occurrences (show event every occurrence day).
     * For non-recurring: returns true (scope already filtered).
     */
    public function isEventRelevantForDate(Event $event, CarbonInterface $date): bool
    {
        $recurringEvent = $event->recurringEvent;
        if ($recurringEvent === null) {
            return true;
        }

        $dateStr = $date instanceof \DateTimeInterface
            ? $date->format('Y-m-d')
            : \Carbon\Carbon::parse($date)->format('Y-m-d');

        $occurrences = $this->getOccurrencesForDateRange($recurringEvent, $date, $date);

        return collect($occurrences)->contains(fn ($d) => $d->format('Y-m-d') === $dateStr);
    }

    /**
     * Expand recurrence pattern into concrete dates within the range.
     * Respects EventException (excludes deleted, applies replacements).
     *
     * @return array<CarbonInterface>
     */
    public function getOccurrencesForDateRange(RecurringEvent $recurringEvent, CarbonInterface $start, CarbonInterface $end): array
    {
        return $this->recurrenceExpander->expand($recurringEvent, $start, $end);
    }

    /**
     * Sync RecurringEvent start_datetime and end_datetime when the parent Event's dates change.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function syncRecurringEventDatesIfNeeded(Event $event, array $attributes): void
    {
        $dateKeys = ['start_datetime', 'end_datetime'];
        $hasDateChanges = array_intersect(array_keys($attributes), $dateKeys) !== [];

        if (! $hasDateChanges) {
            return;
        }

        $recurringEvent = $event->recurringEvent ?? RecurringEvent::where('event_id', $event->id)->first();
        if ($recurringEvent === null) {
            return;
        }

        $syncAttributes = [];
        if (array_key_exists('start_datetime', $attributes)) {
            $syncAttributes['start_datetime'] = $attributes['start_datetime'];
        }
        if (array_key_exists('end_datetime', $attributes)) {
            $syncAttributes['end_datetime'] = $attributes['end_datetime'];
        }

        if ($syncAttributes !== []) {
            $recurringEvent->update($syncAttributes);
        }
    }

    /**
     * Create an EventException to skip or replace an occurrence.
     */
    public function createEventException(
        RecurringEvent $recurringEvent,
        CarbonInterface $date,
        bool $isDeleted,
        ?EventInstance $replacement = null,
        ?User $createdBy = null
    ): EventException {
        $exceptionDate = $date instanceof \DateTimeInterface
            ? $date->format('Y-m-d')
            : \Carbon\Carbon::parse($date)->format('Y-m-d');

        return EventException::query()->updateOrCreate(
            [
                'recurring_event_id' => $recurringEvent->id,
                'exception_date' => $exceptionDate,
            ],
            [
                'is_deleted' => $isDeleted,
                'replacement_instance_id' => $replacement?->id,
                'created_by' => $createdBy?->id,
            ]
        );
    }
}
