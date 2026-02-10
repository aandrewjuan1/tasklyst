<?php

use App\Enums\CollaborationPermission;
use App\Enums\EventStatus;
use App\Models\Collaboration;
use App\Models\CollaborationInvitation;
use App\Models\Event;
use App\Models\RecurringEvent;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->collaborator = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('scope for user returns events owned by the user', function (): void {
    $owned = Event::factory()->for($this->owner)->create(['title' => 'Owned event']);
    Event::factory()->for($this->otherUser)->create(['title' => 'Other event']);

    $events = Event::query()->forUser($this->owner->id)->get();

    expect($events)->toHaveCount(1)
        ->and($events->first()->id)->toBe($owned->id);
});

test('scope for user returns events where user is collaborator', function (): void {
    $event = Event::factory()->for($this->owner)->create(['title' => 'Shared event']);
    Collaboration::create([
        'collaboratable_type' => Event::class,
        'collaboratable_id' => $event->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    $events = Event::query()->forUser($this->collaborator->id)->get();

    expect($events)->toHaveCount(1)
        ->and($events->first()->id)->toBe($event->id);
});

test('scope for user does not return other users events without collaboration', function (): void {
    Event::factory()->for($this->owner)->create(['title' => 'Owner only event']);

    $events = Event::query()->forUser($this->collaborator->id)->get();

    expect($events)->toHaveCount(0);
});

test('scope active for date includes events with no dates', function (): void {
    $event = Event::factory()->for($this->owner)->create([
        'start_datetime' => null,
        'end_datetime' => null,
    ]);

    $date = Carbon::parse('2025-02-10');
    $events = Event::query()->forUser($this->owner->id)->activeForDate($date)->get();

    expect($events->contains('id', $event->id))->toBeTrue();
});

test('scope active for date includes events when date is before or on end date', function (): void {
    $endDate = Carbon::parse('2025-02-15')->endOfDay();
    $event = Event::factory()->for($this->owner)->create([
        'start_datetime' => null,
        'end_datetime' => $endDate,
    ]);

    $date = Carbon::parse('2025-02-10');
    $events = Event::query()->forUser($this->owner->id)->activeForDate($date)->get();

    expect($events->contains('id', $event->id))->toBeTrue();
});

test('scope active for date includes events when date is within start and end range', function (): void {
    $start = Carbon::parse('2025-02-08')->startOfDay();
    $end = Carbon::parse('2025-02-12')->endOfDay();
    $event = Event::factory()->for($this->owner)->create([
        'start_datetime' => $start,
        'end_datetime' => $end,
    ]);

    $date = Carbon::parse('2025-02-10');
    $events = Event::query()->forUser($this->owner->id)->activeForDate($date)->get();

    expect($events->contains('id', $event->id))->toBeTrue();
});

test('scope not cancelled excludes cancelled events', function (): void {
    Event::factory()->for($this->owner)->create(['status' => EventStatus::Scheduled]);
    Event::factory()->for($this->owner)->create(['status' => EventStatus::Cancelled]);

    $events = Event::query()->forUser($this->owner->id)->notCancelled()->get();

    expect($events)->toHaveCount(1)
        ->and($events->first()->status)->not->toBe(EventStatus::Cancelled);
});

test('scope not completed excludes completed events', function (): void {
    Event::factory()->for($this->owner)->create(['status' => EventStatus::Scheduled]);
    Event::factory()->for($this->owner)->create(['status' => EventStatus::Completed]);

    $events = Event::query()->forUser($this->owner->id)->notCompleted()->get();

    expect($events)->toHaveCount(1)
        ->and($events->first()->status)->not->toBe(EventStatus::Completed);
});

test('scope by status filters by status value', function (): void {
    Event::factory()->for($this->owner)->create(['status' => EventStatus::Ongoing]);
    Event::factory()->for($this->owner)->create(['status' => EventStatus::Completed]);

    $events = Event::query()->forUser($this->owner->id)->byStatus(EventStatus::Ongoing->value)->get();

    expect($events)->toHaveCount(1)
        ->and($events->first()->status)->toBe(EventStatus::Ongoing);
});

test('scope order by start time orders chronologically', function (): void {
    $early = Event::factory()->for($this->owner)->create(['start_datetime' => Carbon::parse('2025-02-10 09:00')]);
    $late = Event::factory()->for($this->owner)->create(['start_datetime' => Carbon::parse('2025-02-10 14:00')]);

    $events = Event::query()->forUser($this->owner->id)->orderByStartTime()->get();

    expect($events->first()->id)->toBe($early->id)
        ->and($events->last()->id)->toBe($late->id);
});

test('scope with no date returns only events with null start and end datetime', function (): void {
    $noDate = Event::factory()->for($this->owner)->create([
        'start_datetime' => null,
        'end_datetime' => null,
    ]);
    Event::factory()->for($this->owner)->create([
        'start_datetime' => now(),
        'end_datetime' => null,
    ]);

    $events = Event::query()->forUser($this->owner->id)->withNoDate()->get();

    expect($events)->toHaveCount(1)
        ->and($events->first()->id)->toBe($noDate->id);
});

test('scope overdue returns events with end_datetime before given date', function (): void {
    $pastEnd = Event::factory()->for($this->owner)->create([
        'end_datetime' => Carbon::parse('2025-02-05'),
    ]);
    Event::factory()->for($this->owner)->create([
        'end_datetime' => Carbon::parse('2025-02-15'),
    ]);

    $asOf = Carbon::parse('2025-02-10');
    $events = Event::query()->forUser($this->owner->id)->overdue($asOf)->get();

    expect($events)->toHaveCount(1)
        ->and($events->first()->id)->toBe($pastEnd->id);
});

test('scope upcoming returns events with start_datetime on or after from date', function (): void {
    $from = Carbon::parse('2025-02-10')->startOfDay();
    $upcoming = Event::factory()->for($this->owner)->create([
        'start_datetime' => $from->copy()->addDays(2),
    ]);
    Event::factory()->for($this->owner)->create([
        'start_datetime' => $from->copy()->subDay(),
    ]);

    $events = Event::query()->forUser($this->owner->id)->upcoming($from)->get();

    expect($events)->toHaveCount(1)
        ->and($events->first()->id)->toBe($upcoming->id);
});

test('scope starting soon returns events starting within given days from date', function (): void {
    $from = Carbon::parse('2025-02-10')->startOfDay();
    $soon = Event::factory()->for($this->owner)->create([
        'start_datetime' => $from->copy()->addDays(3),
    ]);
    Event::factory()->for($this->owner)->create([
        'start_datetime' => $from->copy()->addDays(10),
    ]);

    $events = Event::query()->forUser($this->owner->id)->startingSoon($from, 7)->get();

    expect($events)->toHaveCount(1)
        ->and($events->first()->id)->toBe($soon->id);
});

test('scope happening now returns events where given time is between start and end', function (): void {
    $atTime = Carbon::parse('2025-02-10 12:00');
    $event = Event::factory()->for($this->owner)->create([
        'start_datetime' => Carbon::parse('2025-02-10 10:00'),
        'end_datetime' => Carbon::parse('2025-02-10 14:00'),
    ]);

    $events = Event::query()->forUser($this->owner->id)->happeningNow($atTime)->get();

    expect($events->contains('id', $event->id))->toBeTrue();
});

test('scope all day returns only all_day true events', function (): void {
    $allDay = Event::factory()->for($this->owner)->create(['all_day' => true]);
    Event::factory()->for($this->owner)->create(['all_day' => false]);

    $events = Event::query()->forUser($this->owner->id)->allDay()->get();

    expect($events)->toHaveCount(1)
        ->and($events->first()->id)->toBe($allDay->id);
});

test('scope timed returns only non all_day events', function (): void {
    Event::factory()->for($this->owner)->create(['all_day' => true]);
    $timed = Event::factory()->for($this->owner)->create(['all_day' => false]);

    $events = Event::query()->forUser($this->owner->id)->timed()->get();

    expect($events)->toHaveCount(1)
        ->and($events->first()->id)->toBe($timed->id);
});

test('deleting event cascades to collaborations collaboration invitations and recurring event', function (): void {
    $event = Event::factory()->for($this->owner)->create();
    $collab = Collaboration::create([
        'collaboratable_type' => Event::class,
        'collaboratable_id' => $event->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::Edit,
    ]);
    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Event::class,
        'collaboratable_id' => $event->id,
        'inviter_id' => $this->owner->id,
        'invitee_user_id' => $this->collaborator->id,
    ]);
    $recurring = RecurringEvent::factory()->create(['event_id' => $event->id]);

    $event->delete();

    expect(Collaboration::find($collab->id))->toBeNull()
        ->and(CollaborationInvitation::find($invitation->id))->toBeNull()
        ->and(RecurringEvent::find($recurring->id))->toBeNull();
});

test('property to column maps startDatetime endDatetime and allDay to snake_case', function (): void {
    expect(Event::propertyToColumn('startDatetime'))->toBe('start_datetime')
        ->and(Event::propertyToColumn('endDatetime'))->toBe('end_datetime')
        ->and(Event::propertyToColumn('allDay'))->toBe('all_day')
        ->and(Event::propertyToColumn('title'))->toBe('title');
});

test('get property value for update returns correct value for status dates all_day and title', function (): void {
    $event = Event::factory()->for($this->owner)->create([
        'status' => EventStatus::Ongoing,
        'start_datetime' => $start = Carbon::parse('2025-02-10 09:00'),
        'end_datetime' => $end = Carbon::parse('2025-02-11 17:00'),
        'all_day' => true,
        'title' => 'Test Event',
    ]);

    expect($event->getPropertyValueForUpdate('status'))->toBe(EventStatus::Ongoing->value)
        ->and($event->getPropertyValueForUpdate('startDatetime'))->toEqual($start)
        ->and($event->getPropertyValueForUpdate('endDatetime'))->toEqual($end)
        ->and($event->getPropertyValueForUpdate('allDay'))->toBeTrue()
        ->and($event->getPropertyValueForUpdate('title'))->toBe('Test Event');
});
