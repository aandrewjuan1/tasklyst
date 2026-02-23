<?php

use App\Enums\TaskSourceType;
use App\Models\CalendarFeed;
use App\Models\Task;
use App\Models\User;
use App\Services\CalendarFeedSyncService;
use App\Services\IcsParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('creates or updates tasks from calendar feed events', function () {
    $user = User::factory()->create();

    /** @var CalendarFeed $feed */
    $feed = CalendarFeed::query()->create([
        'user_id' => $user->id,
        'name' => 'Brightspace – All Courses',
        'feed_url' => 'https://example.test/calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
    ]);

    $ics = <<<'ICS'
BEGIN:VCALENDAR
BEGIN:VEVENT
UID:event-1@example.com
SUMMARY:Quiz 1
DTSTART:20260301T090000Z
DTEND:20260301T093000Z
END:VEVENT
END:VCALENDAR
ICS;

    Http::fake([
        $feed->feed_url => Http::response($ics, 200),
    ]);

    $service = new CalendarFeedSyncService(new IcsParserService);

    $service->sync($feed);

    $task = Task::query()->where('user_id', $user->id)->first();

    expect($task)->not->toBeNull();
    expect($task->title)->toBe('Quiz 1');
    expect($task->calendar_feed_id)->toBe($feed->id);
    expect($task->source_type)->toBe(TaskSourceType::Brightspace);
    expect($task->source_id)->toBe('event-1@example.com');

    $feed->refresh();
    expect($feed->last_synced_at)->not->toBeNull();
});

it('does not change tasks when http fails', function () {
    $user = User::factory()->create();

    /** @var CalendarFeed $feed */
    $feed = CalendarFeed::query()->create([
        'user_id' => $user->id,
        'name' => 'Brightspace – All Courses',
        'feed_url' => 'https://example.test/calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
    ]);

    Http::fake([
        $feed->feed_url => Http::response('', 500),
    ]);

    $service = new CalendarFeedSyncService(new IcsParserService);

    $service->sync($feed);

    expect(Task::query()->count())->toBe(0);

    $feed->refresh();
    expect($feed->last_synced_at)->toBeNull();
});

it('leaves stale tasks as-is when events disappear from feed', function () {
    $user = User::factory()->create();

    /** @var CalendarFeed $feed */
    $feed = CalendarFeed::query()->create([
        'user_id' => $user->id,
        'name' => 'Brightspace – All Courses',
        'feed_url' => 'https://example.test/calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
    ]);

    /** @var Task $staleTask */
    $staleTask = Task::query()->create([
        'user_id' => $user->id,
        'title' => 'Old Event',
        'description' => null,
        'status' => \App\Enums\TaskStatus::ToDo,
        'priority' => \App\Enums\TaskPriority::Medium,
        'complexity' => \App\Enums\TaskComplexity::Moderate,
        'duration' => null,
        'start_datetime' => null,
        'end_datetime' => null,
        'project_id' => null,
        'event_id' => null,
        'source_type' => TaskSourceType::Brightspace,
        'source_id' => 'stale-uid@example.com',
        'calendar_feed_id' => $feed->id,
    ]);

    $ics = <<<'ICS'
BEGIN:VCALENDAR
END:VCALENDAR
ICS;

    Http::fake([
        $feed->feed_url => Http::response($ics, 200),
    ]);

    $service = new CalendarFeedSyncService(new IcsParserService);

    $service->sync($feed);

    expect(Task::query()->whereKey($staleTask->id)->exists())->toBeTrue();
});
