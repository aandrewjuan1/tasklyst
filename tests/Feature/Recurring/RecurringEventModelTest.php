<?php

use App\Enums\EventRecurrenceType;
use App\Models\Event;
use App\Models\EventException;
use App\Models\EventInstance;
use App\Models\RecurringEvent;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('recurring event belongs to event', function (): void {
    $event = Event::factory()->for($this->user)->create();
    $recurring = RecurringEvent::factory()->create(['event_id' => $event->id]);

    expect($recurring->event)->not->toBeNull()
        ->and($recurring->event->id)->toBe($event->id);
});

test('recurring event has many event instances', function (): void {
    $recurring = RecurringEvent::factory()->create();
    EventInstance::factory()->create(['recurring_event_id' => $recurring->id]);
    EventInstance::factory()->create(['recurring_event_id' => $recurring->id]);

    expect($recurring->eventInstances)->toHaveCount(2);
});

test('recurring event has many event exceptions', function (): void {
    $recurring = RecurringEvent::factory()->create();
    EventException::factory()->create(['recurring_event_id' => $recurring->id]);

    expect($recurring->eventExceptions)->toHaveCount(1);
});

test('to payload array returns disabled structure when null', function (): void {
    $payload = RecurringEvent::toPayloadArray(null);

    expect($payload)->toEqual([
        'enabled' => false,
        'type' => null,
        'interval' => 1,
        'daysOfWeek' => [],
    ]);
});

test('to payload array returns enabled structure with type interval and days of week', function (): void {
    $recurring = RecurringEvent::factory()->create([
        'recurrence_type' => EventRecurrenceType::Weekly,
        'interval' => 2,
        'days_of_week' => json_encode([1, 3]),
    ]);

    $payload = RecurringEvent::toPayloadArray($recurring);

    expect($payload['enabled'])->toBeTrue()
        ->and($payload['type'])->toBe('weekly')
        ->and($payload['interval'])->toBe(2)
        ->and($payload['daysOfWeek'])->toEqual([1, 3]);
});

test('recurring event casts recurrence type to enum', function (): void {
    $recurring = RecurringEvent::factory()->create(['recurrence_type' => EventRecurrenceType::Monthly]);

    expect($recurring->recurrence_type)->toBe(EventRecurrenceType::Monthly);
});

test('recurring event casts start and end datetime', function (): void {
    $start = now()->startOfDay();
    $end = now()->addDays(7);
    $recurring = RecurringEvent::factory()->create([
        'start_datetime' => $start,
        'end_datetime' => $end,
    ]);

    expect($recurring->start_datetime)->toBeInstanceOf(\Carbon\CarbonInterface::class)
        ->and($recurring->end_datetime)->toBeInstanceOf(\Carbon\CarbonInterface::class);
});
