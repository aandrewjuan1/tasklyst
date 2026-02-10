<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventInstance;
use App\Models\RecurringEvent;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('event instance belongs to recurring event', function (): void {
    $recurring = RecurringEvent::factory()->create();
    $instance = EventInstance::factory()->create(['recurring_event_id' => $recurring->id]);

    expect($instance->recurringEvent)->not->toBeNull()
        ->and($instance->recurringEvent->id)->toBe($recurring->id);
});

test('event instance belongs to event', function (): void {
    $event = Event::factory()->for($this->user)->create();
    $recurring = RecurringEvent::factory()->create(['event_id' => $event->id]);
    $instance = EventInstance::factory()->create([
        'recurring_event_id' => $recurring->id,
        'event_id' => $event->id,
    ]);

    expect($instance->event)->not->toBeNull()
        ->and($instance->event->id)->toBe($event->id);
});

test('event instance casts instance date to date', function (): void {
    $instance = EventInstance::factory()->create(['instance_date' => '2025-02-10']);

    expect($instance->instance_date)->toBeInstanceOf(\Carbon\CarbonInterface::class)
        ->and($instance->instance_date->format('Y-m-d'))->toBe('2025-02-10');
});

test('event instance casts status to enum', function (): void {
    $instance = EventInstance::factory()->create(['status' => EventStatus::Scheduled]);

    expect($instance->status)->toBe(EventStatus::Scheduled);
});

test('event instance casts cancelled to boolean', function (): void {
    $instance = EventInstance::factory()->create(['cancelled' => true]);

    expect($instance->cancelled)->toBeTrue();
});
