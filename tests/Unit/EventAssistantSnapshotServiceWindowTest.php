<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use App\Services\LLM\Prioritization\AssistantCandidateProvider;
use Carbon\CarbonImmutable;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('includes overlapping events up to the default future window', function (): void {
    $user = User::factory()->create();

    $now = CarbonImmutable::create(2026, 3, 20, 12, 0, 0, 'Asia/Manila');
    CarbonImmutable::setTestNow($now);

    // Event within the provider's default +24h window -> should be included.
    Event::factory()->create([
        'user_id' => $user->id,
        'title' => 'In window event',
        'start_datetime' => $now->copy()->addHours(23),
        'end_datetime' => $now->copy()->addHours(24),
        'all_day' => false,
        'status' => EventStatus::Scheduled->value,
    ]);

    // Event overlapping within ~10 days -> should NOT be included.
    Event::factory()->create([
        'user_id' => $user->id,
        'title' => 'Out of window event',
        'start_datetime' => $now->copy()->addDays(10),
        'end_datetime' => $now->copy()->addDays(10)->addHours(1),
        'all_day' => false,
        'status' => EventStatus::Scheduled->value,
    ]);

    $snapshot = app(AssistantCandidateProvider::class)->candidatesForUser($user);

    $eventTitles = collect($snapshot['events'] ?? [])->pluck('title')->values()->all();

    expect($eventTitles)->toContain('In window event');
    expect($eventTitles)->not->toContain('Out of window event');

    CarbonImmutable::setTestNow();
});
