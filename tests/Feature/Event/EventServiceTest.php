<?php

use App\Enums\CollaborationPermission;
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

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->service = app(EventService::class);
});

test('create event sets user_id and minimal attributes', function (): void {
    $event = $this->service->createEvent($this->user, [
        'title' => 'Minimal event',
        'status' => EventStatus::Scheduled->value,
    ]);

    expect($event)->toBeInstanceOf(Event::class)
        ->and($event->user_id)->toBe($this->user->id)
        ->and($event->title)->toBe('Minimal event')
        ->and($event->exists)->toBeTrue();
});

test('create event with tag ids attaches tags', function (): void {
    $tag1 = Tag::factory()->for($this->user)->create();
    $tag2 = Tag::factory()->for($this->user)->create();

    $event = $this->service->createEvent($this->user, [
        'title' => 'Tagged event',
        'tagIds' => [$tag1->id, $tag2->id],
    ]);

    $event->load('tags');
    expect($event->tags->pluck('id')->all())->toEqualCanonicalizing([$tag1->id, $tag2->id]);
});

test('create event with recurrence enabled creates recurring event', function (): void {
    $event = $this->service->createEvent($this->user, [
        'title' => 'Recurring event',
        'recurrence' => [
            'enabled' => true,
            'type' => EventRecurrenceType::Weekly->value,
            'interval' => 2,
            'daysOfWeek' => [1, 3],
        ],
    ]);

    $event->load('recurringEvent');
    expect($event->recurringEvent)->not->toBeNull()
        ->and($event->recurringEvent->recurrence_type->value)->toBe(EventRecurrenceType::Weekly->value)
        ->and($event->recurringEvent->interval)->toBe(2)
        ->and(json_decode($event->recurringEvent->days_of_week, true))->toEqual([1, 3]);
});

test('update event updates attributes', function (): void {
    $event = Event::factory()->for($this->user)->create(['title' => 'Original']);

    $updated = $this->service->updateEvent($event, ['title' => 'Updated title']);

    expect($updated->title)->toBe('Updated title')
        ->and($event->fresh()->title)->toBe('Updated title');
});

test('update event start and end datetime syncs to recurring event', function (): void {
    $event = Event::factory()->for($this->user)->create([
        'start_datetime' => null,
        'end_datetime' => null,
    ]);
    RecurringEvent::factory()->create([
        'event_id' => $event->id,
        'start_datetime' => null,
        'end_datetime' => null,
    ]);

    $newStart = Carbon::parse('2025-03-01 09:00');
    $newEnd = Carbon::parse('2025-03-31 17:00');
    $this->service->updateEvent($event, [
        'start_datetime' => $newStart,
        'end_datetime' => $newEnd,
    ]);

    $recurring = $event->recurringEvent()->first();
    expect($recurring)->not->toBeNull()
        ->and($recurring->start_datetime->format('Y-m-d H:i'))->toBe($newStart->format('Y-m-d H:i'))
        ->and($recurring->end_datetime->format('Y-m-d H:i'))->toBe($newEnd->format('Y-m-d H:i'));
});

test('delete event soft deletes and boot removes related records', function (): void {
    $event = Event::factory()->for($this->user)->create();
    $recurring = RecurringEvent::factory()->create(['event_id' => $event->id]);
    $collab = $event->collaborations()->create([
        'user_id' => User::factory()->create()->id,
        'permission' => CollaborationPermission::Edit,
    ]);
    $invitation = $event->collaborationInvitations()->create([
        'inviter_id' => $this->user->id,
        'invitee_email' => 'a@b.com',
        'permission' => CollaborationPermission::View,
        'status' => 'pending',
        'token' => \Illuminate\Support\Str::random(32),
    ]);

    $result = $this->service->deleteEvent($event);

    expect($result)->toBeTrue();
    expect(Event::withTrashed()->find($event->id))->not->toBeNull()
        ->and(Event::withTrashed()->find($event->id)->trashed())->toBeTrue();
    expect(RecurringEvent::find($recurring->id))->toBeNull();
    expect($collab->fresh())->toBeNull();
    expect($invitation->fresh())->toBeNull();
});

test('update or create recurring event creates when enabled with type', function (): void {
    $event = Event::factory()->for($this->user)->create();

    $this->service->updateOrCreateRecurringEvent($event, [
        'enabled' => true,
        'type' => EventRecurrenceType::Daily->value,
        'interval' => 1,
        'daysOfWeek' => [],
    ]);

    $event->load('recurringEvent');
    expect($event->recurringEvent)->not->toBeNull()
        ->and($event->recurringEvent->recurrence_type->value)->toBe('daily');
});

test('update or create recurring event replaces existing when called again', function (): void {
    $event = Event::factory()->for($this->user)->create();
    $this->service->updateOrCreateRecurringEvent($event, [
        'enabled' => true,
        'type' => EventRecurrenceType::Daily->value,
        'interval' => 1,
        'daysOfWeek' => [],
    ]);
    $firstId = $event->recurringEvent->id;

    $this->service->updateOrCreateRecurringEvent($event, [
        'enabled' => true,
        'type' => EventRecurrenceType::Weekly->value,
        'interval' => 1,
        'daysOfWeek' => [1],
    ]);

    $event->load('recurringEvent');
    expect(RecurringEvent::find($firstId))->toBeNull()
        ->and($event->recurringEvent->recurrence_type->value)->toBe('weekly');
});

test('update or create recurring event disables by deleting when enabled false', function (): void {
    $event = Event::factory()->for($this->user)->create();
    $this->service->updateOrCreateRecurringEvent($event, [
        'enabled' => true,
        'type' => EventRecurrenceType::Daily->value,
        'interval' => 1,
        'daysOfWeek' => [],
    ]);
    $recurringId = $event->recurringEvent->id;

    $this->service->updateOrCreateRecurringEvent($event, ['enabled' => false, 'type' => null]);

    expect(RecurringEvent::find($recurringId))->toBeNull();
});

test('update recurring occurrence status creates event instance', function (): void {
    $event = Event::factory()->for($this->user)->create();
    $recurring = RecurringEvent::factory()->create([
        'event_id' => $event->id,
        'start_datetime' => Carbon::parse('2025-02-01'),
        'end_datetime' => Carbon::parse('2025-02-28'),
    ]);
    $date = Carbon::parse('2025-02-10');

    $instance = $this->service->updateRecurringOccurrenceStatus($event, $date, EventStatus::Completed);

    expect($instance)->toBeInstanceOf(EventInstance::class)
        ->and($instance->recurring_event_id)->toBe($recurring->id)
        ->and($instance->instance_date->format('Y-m-d'))->toBe('2025-02-10')
        ->and($instance->status)->toBe(EventStatus::Completed)
        ->and($instance->completed_at)->not->toBeNull();
});

test('update recurring occurrence status sets cancelled for cancelled status', function (): void {
    $event = Event::factory()->for($this->user)->create();
    $recurring = RecurringEvent::factory()->create(['event_id' => $event->id]);
    $date = Carbon::parse('2025-02-10');

    $instance = $this->service->updateRecurringOccurrenceStatus($event, $date, EventStatus::Cancelled);

    expect($instance->cancelled)->toBeTrue();
});

test('update recurring occurrence status updates existing instance', function (): void {
    $event = Event::factory()->for($this->user)->create();
    $recurring = RecurringEvent::factory()->create(['event_id' => $event->id]);
    $date = Carbon::parse('2025-02-10');
    EventInstance::factory()->create([
        'recurring_event_id' => $recurring->id,
        'event_id' => $event->id,
        'instance_date' => $date,
        'status' => EventStatus::Scheduled,
    ]);

    $instance = $this->service->updateRecurringOccurrenceStatus($event, $date, EventStatus::Ongoing);

    expect($instance->status)->toBe(EventStatus::Ongoing);
    expect(EventInstance::where('recurring_event_id', $recurring->id)->whereDate('instance_date', $date)->count())->toBe(1);
});

test('get effective status for date returns base status for non recurring event', function (): void {
    $event = Event::factory()->for($this->user)->create(['status' => EventStatus::Ongoing]);

    $status = $this->service->getEffectiveStatusForDate($event, Carbon::parse('2025-02-10'));

    expect($status)->toBe(EventStatus::Ongoing);
});

test('get effective status for date returns instance status when instance exists', function (): void {
    $event = Event::factory()->for($this->user)->create(['status' => EventStatus::Scheduled]);
    $recurring = RecurringEvent::factory()->create(['event_id' => $event->id]);
    $date = Carbon::parse('2025-02-10');
    EventInstance::factory()->create([
        'recurring_event_id' => $recurring->id,
        'event_id' => $event->id,
        'instance_date' => $date,
        'status' => EventStatus::Completed,
    ]);
    $event->load('recurringEvent');
    $event->instanceForDate = $event->recurringEvent->eventInstances()->whereDate('instance_date', $date)->first();
    $status = $this->service->getEffectiveStatusForDate($event, $date);

    expect($status)->toBe(EventStatus::Completed);
});

test('process recurring events for date filters and attaches instance and effective status', function (): void {
    $event = Event::factory()->for($this->user)->create();
    $recurring = RecurringEvent::factory()->create([
        'event_id' => $event->id,
        'recurrence_type' => EventRecurrenceType::Daily,
        'start_datetime' => Carbon::parse('2025-02-01'),
        'end_datetime' => Carbon::parse('2025-02-28'),
    ]);
    $event->setRelation('recurringEvent', $recurring);
    $date = Carbon::parse('2025-02-10');

    $result = $this->service->processRecurringEventsForDate(collect([$event]), $date);

    expect($result)->toHaveCount(1);
    $processed = $result->first();
    expect($processed->effectiveStatusForDate)->toBeInstanceOf(EventStatus::class);
});

test('is event relevant for date returns true for non recurring event', function (): void {
    $event = Event::factory()->for($this->user)->create();

    $relevant = $this->service->isEventRelevantForDate($event, Carbon::parse('2025-02-10'));

    expect($relevant)->toBeTrue();
});

test('is event relevant for date returns true when date is in recurring occurrences', function (): void {
    $event = Event::factory()->for($this->user)->create();
    RecurringEvent::factory()->create([
        'event_id' => $event->id,
        'recurrence_type' => EventRecurrenceType::Daily,
        'start_datetime' => Carbon::parse('2025-02-01'),
        'end_datetime' => Carbon::parse('2025-02-28'),
    ]);
    $event->load('recurringEvent');

    $relevant = $this->service->isEventRelevantForDate($event, Carbon::parse('2025-02-10'));

    expect($relevant)->toBeTrue();
});

test('create event exception creates or updates exception', function (): void {
    $recurring = RecurringEvent::factory()->create();
    $date = Carbon::parse('2025-02-10');

    $exception = $this->service->createEventException($recurring, $date, true, null, $this->user);

    expect($exception)->toBeInstanceOf(EventException::class)
        ->and($exception->recurring_event_id)->toBe($recurring->id)
        ->and($exception->exception_date->format('Y-m-d'))->toBe('2025-02-10')
        ->and($exception->is_deleted)->toBeTrue();
});
