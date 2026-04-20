<?php

use App\Enums\ReminderType;
use App\Models\CalendarFeed;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('load calendar feed health returns connected feeds for the authenticated user', function (): void {
    $this->actingAs($this->user);

    $feed = CalendarFeed::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Brightspace',
        'feed_url' => 'https://example.test/calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
        'last_synced_at' => now(),
    ]);

    $component = Livewire::test('pages::workspace.index');
    $payload = $component->instance()->loadCalendarFeedHealth();

    expect($payload)->toBeArray()->not->toBeEmpty();
    expect(collect($payload)->pluck('id')->contains($feed->id))->toBeTrue();
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
        ->assertDispatched('toast', type: 'info', skipDedupe: true);

    expect(Task::query()->where('user_id', $this->user->id)->where('source_id', 'event-1@example.com')->exists())->toBeTrue();
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
        ->assertDispatched('toast', type: 'info', skipDedupe: true);

    expect(Reminder::query()
        ->where('user_id', $this->user->id)
        ->where('type', ReminderType::CalendarFeedSyncFailed->value)
        ->exists())->toBeTrue();
});
