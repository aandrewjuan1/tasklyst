<?php

use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;

test('scope upcoming for user returns only upcoming events for owner within hours window', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $now = now();

    Event::factory()->for($owner)->create([
        'title' => 'Past event',
        'start_datetime' => $now->copy()->subHour(),
    ]);

    $insideWindow = Event::factory()->for($owner)->create([
        'title' => 'Inside window',
        'start_datetime' => $now->copy()->addHours(2),
    ]);

    Event::factory()->for($owner)->create([
        'title' => 'Outside window',
        'start_datetime' => $now->copy()->addHours(30),
    ]);

    Event::factory()->for($other)->create([
        'title' => 'Other user event',
        'start_datetime' => $now->copy()->addHours(2),
    ]);

    $events = Event::query()->upcomingForUser($owner->id, 24)->get();

    expect($events->pluck('id')->values()->all())
        ->toBe([$insideWindow->id]);
});

test('scope conflicting with window returns events that overlap proposed window', function (): void {
    $user = User::factory()->create();

    $start = new CarbonImmutable('2026-03-14 10:00:00');
    $end = new CarbonImmutable('2026-03-14 11:00:00');

    $overlapBefore = Event::factory()->for($user)->create([
        'title' => 'Overlap before',
        'start_datetime' => $start->copy()->subMinutes(30),
        'end_datetime' => $start->copy()->addMinutes(15),
    ]);

    $overlapInside = Event::factory()->for($user)->create([
        'title' => 'Overlap inside',
        'start_datetime' => $start->copy()->addMinutes(15),
        'end_datetime' => $end->copy()->subMinutes(15),
    ]);

    $overlapAfter = Event::factory()->for($user)->create([
        'title' => 'Overlap after',
        'start_datetime' => $end->copy()->subMinutes(15),
        'end_datetime' => $end->copy()->addMinutes(30),
    ]);

    Event::factory()->for($user)->create([
        'title' => 'No overlap before',
        'start_datetime' => $start->copy()->subHours(2),
        'end_datetime' => $start->copy()->subHour(),
    ]);

    Event::factory()->for($user)->create([
        'title' => 'No overlap after',
        'start_datetime' => $end->copy()->addHour(),
        'end_datetime' => $end->copy()->addHours(2),
    ]);

    $otherUser = User::factory()->create();

    Event::factory()->for($otherUser)->create([
        'title' => 'Other user overlap',
        'start_datetime' => $start->copy()->addMinutes(10),
        'end_datetime' => $end->copy()->subMinutes(10),
    ]);

    $events = Event::query()->conflictingWithWindow($user->id, $start, $end)->get();

    expect($events->pluck('id')->sort()->values()->all())
        ->toBe([
            $overlapBefore->id,
            $overlapInside->id,
            $overlapAfter->id,
        ]);
}
);
