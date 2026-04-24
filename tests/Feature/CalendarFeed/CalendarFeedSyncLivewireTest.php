<?php

use App\Enums\ReminderType;
use App\Enums\TaskSourceType;
use App\Enums\TaskStatus;
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
    expect($payload[0])->toHaveKey('exclude_overdue_items');
    expect($payload[0])->toHaveKey('import_past_months');
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
        ->assertDispatched('toast', type: 'error', skipDedupe: true);

    expect(Reminder::query()
        ->where('user_id', $this->user->id)
        ->where('type', ReminderType::CalendarFeedSyncFailed->value)
        ->exists())->toBeTrue();
});

test('updates calendar feed overdue policy for owned feed', function (): void {
    $this->actingAs($this->user);

    $feed = CalendarFeed::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Brightspace',
        'feed_url' => 'https://example.test/calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
        'exclude_overdue_items' => false,
    ]);

    Livewire::test('pages::workspace.index')
        ->call('updateCalendarFeedOverduePolicy', $feed->id, true)
        ->assertDispatched('toast', type: 'info', skipDedupe: true);

    expect($feed->fresh()->exclude_overdue_items)->toBeTrue();
});

test('updates calendar feed import past months per feed', function (): void {
    $this->actingAs($this->user);

    $feed = CalendarFeed::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Brightspace',
        'feed_url' => 'https://example.test/calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
        'import_past_months' => 3,
    ]);

    Livewire::test('pages::workspace.index')
        ->call('updateCalendarFeedImportPastMonths', $feed->id, 6)
        ->assertDispatched('toast', type: 'info', skipDedupe: true);

    expect($feed->fresh()->import_past_months)->toBe(6);
});

test('does not update calendar feed overdue policy for non-owned feed', function (): void {
    $owner = User::factory()->create();
    $this->actingAs($this->user);

    $feed = CalendarFeed::query()->create([
        'user_id' => $owner->id,
        'name' => 'Other Feed',
        'feed_url' => 'https://example.test/other.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
        'exclude_overdue_items' => false,
    ]);

    Livewire::test('pages::workspace.index')
        ->call('updateCalendarFeedOverduePolicy', $feed->id, true);

    expect($feed->fresh()->exclude_overdue_items)->toBeFalse();
});

test('overdue retrieval excludes brightspace overdue tasks for flagged feeds', function (): void {
    $this->actingAs($this->user);

    $feed = CalendarFeed::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Brightspace',
        'feed_url' => 'https://example.test/calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
        'exclude_overdue_items' => true,
    ]);

    Task::query()->create([
        'user_id' => $this->user->id,
        'title' => 'Overdue imported item',
        'description' => null,
        'status' => TaskStatus::ToDo,
        'priority' => \App\Enums\TaskPriority::Medium,
        'complexity' => \App\Enums\TaskComplexity::Moderate,
        'duration' => null,
        'start_datetime' => null,
        'end_datetime' => now()->subDay(),
        'project_id' => null,
        'event_id' => null,
        'source_type' => TaskSourceType::Brightspace,
        'source_id' => 'overdue-hidden@example.com',
        'calendar_feed_id' => $feed->id,
    ]);

    $overdue = Livewire::test('pages::workspace.index')->instance()->overdue();

    expect($overdue)->toBeEmpty();
});
