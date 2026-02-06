<?php

use App\Enums\EventRecurrenceType;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventException;
use App\Models\EventInstance;
use App\Models\RecurringEvent;
use App\Models\Tag;
use App\Models\User;
use App\Services\EventService;
use Carbon\Carbon;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertSoftDeleted;

it('creates an event with tags', function (): void {
    $user = User::factory()->create();
    $tag1 = Tag::factory()->for($user)->create();
    $tag2 = Tag::factory()->for($user)->create();

    $event = app(EventService::class)->createEvent($user, [
        'title' => 'Event with Tags',
        'tagIds' => [$tag1->id, $tag2->id],
    ]);

    expect($event->tags)->toHaveCount(2);
    expect($event->tags->pluck('id')->toArray())->toContain($tag1->id, $tag2->id);
});

it('creates updates and deletes an event', function (): void {
    $user = User::factory()->create();

    $event = app(EventService::class)->createEvent($user, [
        'title' => 'Before',
    ]);

    expect($event)->toBeInstanceOf(Event::class);
    expect($event->user_id)->toBe($user->id);

    $updated = app(EventService::class)->updateEvent($event, [
        'title' => 'After',
        'user_id' => User::factory()->create()->id,
    ]);

    expect($updated->title)->toBe('After');
    expect($updated->user_id)->toBe($user->id);

    assertDatabaseHas('events', [
        'id' => $event->id,
        'user_id' => $user->id,
        'title' => 'After',
    ]);

    $deleted = app(EventService::class)->deleteEvent($event);
    expect($deleted)->toBeTrue();

    assertSoftDeleted('events', [
        'id' => $event->id,
    ]);
});

it('completeRecurringOccurrence creates or updates EventInstance', function (): void {
    Carbon::setTestNow('2026-02-06 10:00:00');

    $event = Event::factory()->create([
        'start_datetime' => Carbon::parse('2026-02-01 09:00:00'),
    ]);
    RecurringEvent::query()->create([
        'event_id' => $event->id,
        'recurrence_type' => EventRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-01-01 00:00:00'),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    $instance = app(EventService::class)->completeRecurringOccurrence($event, Carbon::parse('2026-02-06'));

    expect($instance)->toBeInstanceOf(EventInstance::class);
    expect($instance->recurring_event_id)->toBe($event->recurringEvent->id);
    expect($instance->instance_date->format('Y-m-d'))->toBe('2026-02-06');
    expect($instance->status->value)->toBe('completed');
    expect($instance->completed_at)->not->toBeNull();
    expect($instance->event_id)->toBe($event->id);

    assertDatabaseHas('event_instances', [
        'recurring_event_id' => $event->recurringEvent->id,
        'status' => 'completed',
    ]);
});

it('completeRecurringOccurrence throws when event has no recurring event', function (): void {
    $event = Event::factory()->create();

    app(EventService::class)->completeRecurringOccurrence($event, Carbon::parse('2026-02-06'));
})->throws(\InvalidArgumentException::class);

it('updateRecurringOccurrenceStatus creates or updates instance with any status', function (): void {
    Carbon::setTestNow('2026-02-06 10:00:00');

    $event = Event::factory()->create([
        'start_datetime' => Carbon::parse('2026-02-01 09:00:00'),
        'status' => EventStatus::Scheduled,
    ]);
    RecurringEvent::query()->create([
        'event_id' => $event->id,
        'recurrence_type' => EventRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-01-01 00:00:00'),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    $instance = app(EventService::class)->updateRecurringOccurrenceStatus($event, Carbon::parse('2026-02-06'), EventStatus::Ongoing);

    expect($instance->status->value)->toBe('ongoing');
    expect($instance->completed_at)->toBeNull();

    $instance = app(EventService::class)->updateRecurringOccurrenceStatus($event, Carbon::parse('2026-02-06'), EventStatus::Completed);

    expect($instance->status->value)->toBe('completed');
    expect($instance->completed_at)->not->toBeNull();
});

it('updateRecurringOccurrenceStatus updates existing instance instead of creating duplicate', function (): void {
    Carbon::setTestNow('2026-02-06 10:00:00');

    $event = Event::factory()->create([
        'start_datetime' => Carbon::parse('2026-02-01 09:00:00'),
        'status' => EventStatus::Scheduled,
    ]);
    RecurringEvent::query()->create([
        'event_id' => $event->id,
        'recurrence_type' => EventRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-01-01 00:00:00'),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    $instance1 = app(EventService::class)->updateRecurringOccurrenceStatus($event, Carbon::parse('2026-02-06'), EventStatus::Ongoing);
    $instance2 = app(EventService::class)->updateRecurringOccurrenceStatus($event, Carbon::parse('2026-02-06'), EventStatus::Completed);

    expect($instance1->id)->toBe($instance2->id);
    expect($instance2->status->value)->toBe('completed');

    $count = EventInstance::query()
        ->where('recurring_event_id', $event->recurringEvent->id)
        ->whereDate('instance_date', '2026-02-06')
        ->count();

    expect($count)->toBe(1);
});

it('getEffectiveStatusForDate returns instance status when instance exists', function (): void {
    Carbon::setTestNow('2026-02-06 10:00:00');

    $event = Event::factory()->create(['status' => EventStatus::Scheduled]);
    $recurring = RecurringEvent::query()->create([
        'event_id' => $event->id,
        'recurrence_type' => EventRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-01-01 00:00:00'),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    $effectiveStatus = app(EventService::class)->getEffectiveStatusForDate($event->load('recurringEvent'), Carbon::parse('2026-02-06'));
    expect($effectiveStatus)->toBe(EventStatus::Scheduled);

    EventInstance::query()->create([
        'recurring_event_id' => $recurring->id,
        'event_id' => $event->id,
        'instance_date' => '2026-02-06',
        'status' => EventStatus::Completed,
    ]);

    $effectiveStatus = app(EventService::class)->getEffectiveStatusForDate($event->load('recurringEvent'), Carbon::parse('2026-02-06'));
    expect($effectiveStatus)->toBe(EventStatus::Completed);

    $effectiveStatus = app(EventService::class)->getEffectiveStatusForDate($event->load('recurringEvent'), Carbon::parse('2026-02-07'));
    expect($effectiveStatus)->toBe(EventStatus::Scheduled);
});

it('isEventActiveForDate shows recurring event even when instance is completed', function (): void {
    Carbon::setTestNow('2026-02-06 10:00:00');

    $event = Event::factory()->create([
        'start_datetime' => Carbon::parse('2026-02-01 09:00:00'),
    ]);
    RecurringEvent::query()->create([
        'event_id' => $event->id,
        'recurrence_type' => EventRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-01-01 00:00:00'),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    EventInstance::query()->create([
        'recurring_event_id' => $event->recurringEvent->id,
        'event_id' => $event->id,
        'instance_date' => '2026-02-06',
        'status' => EventStatus::Completed,
        'completed_at' => now(),
    ]);

    $eventService = app(EventService::class);
    expect($eventService->isEventActiveForDate($event->load('recurringEvent'), Carbon::parse('2026-02-06')))->toBeTrue();
});

it('deleteEvent deletes recurring event and event instances', function (): void {
    Carbon::setTestNow('2026-02-06 10:00:00');

    $event = app(EventService::class)->createEvent(User::factory()->create(), [
        'title' => 'Recurring Event',
        'start_datetime' => now()->startOfDay()->addHours(9),
        'recurrence' => [
            'enabled' => true,
            'type' => 'daily',
            'interval' => 1,
            'daysOfWeek' => [],
        ],
    ]);

    $recurringEventId = $event->recurringEvent->id;
    EventInstance::query()->create([
        'recurring_event_id' => $recurringEventId,
        'event_id' => $event->id,
        'instance_date' => '2026-02-06',
        'status' => EventStatus::Completed,
    ]);

    app(EventService::class)->deleteEvent($event);

    assertSoftDeleted('events', ['id' => $event->id]);
    expect(RecurringEvent::query()->find($recurringEventId))->toBeNull();
    expect(EventInstance::query()->where('recurring_event_id', $recurringEventId)->count())->toBe(0);
});

it('getOccurrencesForDateRange returns expanded dates for events', function (): void {
    $event = Event::factory()->create([
        'start_datetime' => Carbon::parse('2026-02-01 09:00:00'),
    ]);
    $recurring = RecurringEvent::query()->create([
        'event_id' => $event->id,
        'recurrence_type' => EventRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-02-01 00:00:00'),
        'end_datetime' => Carbon::parse('2026-02-05 23:59:59'),
        'days_of_week' => null,
    ]);

    $dates = app(EventService::class)->getOccurrencesForDateRange(
        $recurring,
        Carbon::parse('2026-02-01'),
        Carbon::parse('2026-02-05')
    );

    expect($dates)->toHaveCount(5);
    expect($dates[0]->format('Y-m-d'))->toBe('2026-02-01');
    expect($dates[4]->format('Y-m-d'))->toBe('2026-02-05');
});

it('createEventException creates or updates exception', function (): void {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'start_datetime' => Carbon::parse('2026-02-01 09:00:00'),
    ]);
    $recurring = RecurringEvent::query()->create([
        'event_id' => $event->id,
        'recurrence_type' => EventRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-01-01 00:00:00'),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    $exception = app(EventService::class)->createEventException(
        $recurring,
        Carbon::parse('2026-02-10'),
        true,
        null,
        $user
    );

    expect($exception)->toBeInstanceOf(EventException::class);
    expect($exception->recurring_event_id)->toBe($recurring->id);
    expect($exception->exception_date->format('Y-m-d'))->toBe('2026-02-10');
    expect($exception->is_deleted)->toBeTrue();
    expect($exception->created_by)->toBe($user->id);

    assertDatabaseHas('event_exceptions', [
        'recurring_event_id' => $recurring->id,
        'is_deleted' => true,
    ]);
});
