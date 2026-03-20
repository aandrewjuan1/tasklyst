<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('includes events overlapping the assistant window (started before now)', function (): void {
    $user = User::factory()->create();

    $now = \Carbon\CarbonImmutable::create(2026, 3, 20, 12, 0, 0, 'Asia/Manila');

    // Included: started 2h ago, ends 2h in the future (overlaps window)
    Event::factory()->create([
        'user_id' => $user->id,
        'title' => 'Ongoing meeting',
        'start_datetime' => $now->subHours(2),
        'end_datetime' => $now->addHours(2),
        'all_day' => false,
        'status' => EventStatus::Scheduled->value,
    ]);

    // Excluded: ended before windowStart (started long ago + finished)
    Event::factory()->create([
        'user_id' => $user->id,
        'title' => 'Old event',
        'start_datetime' => $now->subHours(12),
        'end_datetime' => $now->subHours(7),
        'all_day' => false,
        'status' => EventStatus::Scheduled->value,
    ]);

    // Included: starts within the future window
    Event::factory()->create([
        'user_id' => $user->id,
        'title' => 'Meeting soon',
        'start_datetime' => $now->addHours(10),
        'end_datetime' => $now->addHours(11),
        'all_day' => false,
        'status' => EventStatus::Scheduled->value,
    ]);

    // Excluded: starts after the future window end
    Event::factory()->create([
        'user_id' => $user->id,
        'title' => 'Too far',
        'start_datetime' => $now->addHours(40),
        'end_datetime' => $now->addHours(41),
        'all_day' => false,
        'status' => EventStatus::Scheduled->value,
    ]);

    $ids = Event::query()
        ->forAssistantSnapshot($user->id, $now, 24, 10, 6)
        ->pluck('title')
        ->values()
        ->all();

    expect($ids)->toContain('Ongoing meeting');
    expect($ids)->toContain('Meeting soon');
    expect($ids)->not->toContain('Old event');
    expect($ids)->not->toContain('Too far');
});
