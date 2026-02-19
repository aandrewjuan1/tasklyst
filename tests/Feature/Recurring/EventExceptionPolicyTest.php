<?php

use App\Enums\CollaborationPermission;
use App\Models\Event;
use App\Models\EventException;
use App\Models\RecurringEvent;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->collaboratorWithEdit = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('owner can view update and delete event exception', function (): void {
    $event = Event::factory()->for($this->owner)->create();
    $recurring = RecurringEvent::factory()->create(['event_id' => $event->id]);
    $exception = EventException::factory()->create(['recurring_event_id' => $recurring->id]);

    expect($this->owner->can('view', $exception))->toBeTrue()
        ->and($this->owner->can('update', $exception))->toBeTrue()
        ->and($this->owner->can('delete', $exception))->toBeTrue();
});

test('collaborator with edit permission can view update and delete event exception', function (): void {
    $event = Event::factory()->for($this->owner)->create();
    $event->collaborations()->create([
        'user_id' => $this->collaboratorWithEdit->id,
        'permission' => CollaborationPermission::Edit,
    ]);
    $recurring = RecurringEvent::factory()->create(['event_id' => $event->id]);
    $exception = EventException::factory()->create(['recurring_event_id' => $recurring->id]);

    expect($this->collaboratorWithEdit->can('view', $exception))->toBeTrue()
        ->and($this->collaboratorWithEdit->can('update', $exception))->toBeTrue()
        ->and($this->collaboratorWithEdit->can('delete', $exception))->toBeTrue();
});

test('other user cannot view update or delete event exception', function (): void {
    $event = Event::factory()->for($this->owner)->create();
    $recurring = RecurringEvent::factory()->create(['event_id' => $event->id]);
    $exception = EventException::factory()->create(['recurring_event_id' => $recurring->id]);

    expect($this->otherUser->can('view', $exception))->toBeFalse()
        ->and($this->otherUser->can('update', $exception))->toBeFalse()
        ->and($this->otherUser->can('delete', $exception))->toBeFalse();
});

test('view any allows any authenticated user', function (): void {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('viewAny', EventException::class))->toBeTrue();
});
