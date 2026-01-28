<?php

use App\Models\Event;
use App\Models\User;
use App\Services\EventService;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertSoftDeleted;

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
