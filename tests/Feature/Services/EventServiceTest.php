<?php

use App\Models\Event;
use App\Models\Tag;
use App\Models\User;
use App\Services\EventService;

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
