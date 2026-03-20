<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantSnapshotService;
use Carbon\CarbonImmutable;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('includes overlapping events up to the default future window', function (): void {
    $user = User::factory()->create();

    $now = CarbonImmutable::create(2026, 3, 20, 12, 0, 0, 'Asia/Manila');

    // Event overlapping within ~2 days -> should be included
    Event::factory()->create([
        'user_id' => $user->id,
        'title' => 'In window event',
        'start_datetime' => $now->copy()->addHours(30),
        'end_datetime' => $now->copy()->addHours(35),
        'all_day' => false,
        'status' => EventStatus::Scheduled->value,
    ]);

    // Event overlapping within ~10 days -> should NOT be included by the default snapshot horizon (7 days)
    Event::factory()->create([
        'user_id' => $user->id,
        'title' => 'Out of window event',
        'start_datetime' => $now->copy()->addDays(10),
        'end_datetime' => $now->copy()->addDays(10)->addHours(1),
        'all_day' => false,
        'status' => EventStatus::Scheduled->value,
    ]);

    $snapshot = app(TaskAssistantSnapshotService::class)->buildForUser($user);

    $eventTitles = collect($snapshot['events'] ?? [])->pluck('title')->values()->all();

    expect($eventTitles)->toContain('In window event');
    expect($eventTitles)->not->toContain('Out of window event');
});
