<?php

use App\Models\CalendarFeed;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('manual sync dispatches success toast with skipDedupe when feed loads', function (): void {
    $this->actingAs($this->user);

    $feed = CalendarFeed::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Brightspace',
        'feed_url' => 'https://example.test/calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
    ]);

    $start = now()->utc()->addDays(10)->setTime(9, 0, 0);
    $end = $start->copy()->addMinutes(30);
    $ics = <<<ICS
BEGIN:VCALENDAR
BEGIN:VEVENT
UID:event-1@example.com
SUMMARY:Quiz 1
DTSTART:{$start->format('Ymd\THis\Z')}
DTEND:{$end->format('Ymd\THis\Z')}
END:VEVENT
END:VCALENDAR
ICS;

    Http::fake([
        $feed->feed_url => Http::response($ics, 200),
    ]);

    Livewire::test('pages::workspace.index')
        ->call('syncCalendarFeed', $feed->id)
        ->assertDispatched('toast', type: 'success', skipDedupe: true);
});

test('manual sync dispatches error toast when feed http fails', function (): void {
    $this->actingAs($this->user);

    $feed = CalendarFeed::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Brightspace',
        'feed_url' => 'https://example.test/calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
    ]);

    Http::fake([
        $feed->feed_url => Http::response('', 500),
    ]);

    Livewire::test('pages::workspace.index')
        ->call('syncCalendarFeed', $feed->id)
        ->assertDispatched('toast', type: 'error', skipDedupe: true);
});
