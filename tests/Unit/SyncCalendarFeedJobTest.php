<?php

use App\Enums\TaskSourceType;
use App\Jobs\SyncCalendarFeedJob;
use App\Models\CalendarFeed;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('syncs the feed when the job runs', function () {
    $user = User::factory()->create();

    /** @var CalendarFeed $feed */
    $feed = CalendarFeed::query()->create([
        'user_id' => $user->id,
        'name' => 'Brightspace',
        'feed_url' => 'https://example.test/job-calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
    ]);

    $start = now()->utc()->addDays(5)->setTime(10, 0, 0);
    $end = $start->copy()->addHour();
    $ics = <<<ICS
BEGIN:VCALENDAR
BEGIN:VEVENT
UID:job-event@example.com
SUMMARY:From job
DTSTART:{$start->format('Ymd\THis\Z')}
DTEND:{$end->format('Ymd\THis\Z')}
END:VEVENT
END:VCALENDAR
ICS;

    Http::fake([
        $feed->feed_url => Http::response($ics, 200),
    ]);

    SyncCalendarFeedJob::dispatchSync($feed->id, (int) $user->id, false);

    expect(Task::query()
        ->where('user_id', $user->id)
        ->where('source_type', TaskSourceType::Brightspace)
        ->where('source_id', 'job-event@example.com')
        ->exists())->toBeTrue();
});

it('does nothing when the feed does not belong to the user id on the job', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    /** @var CalendarFeed $feed */
    $feed = CalendarFeed::query()->create([
        'user_id' => $owner->id,
        'name' => 'Brightspace',
        'feed_url' => 'https://example.test/job-calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
    ]);

    Http::fake([
        $feed->feed_url => Http::response("BEGIN:VCALENDAR\nEND:VCALENDAR", 200),
    ]);

    SyncCalendarFeedJob::dispatchSync($feed->id, (int) $other->id, false);

    expect(Task::query()->where('user_id', $owner->id)->count())->toBe(0);
});
