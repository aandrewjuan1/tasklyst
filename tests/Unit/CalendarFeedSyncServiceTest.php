<?php

use App\Enums\TaskSourceType;
use App\Models\CalendarFeed;
use App\Models\Task;
use App\Models\User;
use App\Notifications\CalendarFeedSyncCompletedNotification;
use App\Services\CalendarFeedSyncService;
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

    $start = now()->utc()->addDays(10)->setTime(9, 0, 0);
    $end = $start->copy()->addMinutes(30);
    $ics = <<<ICS
BEGIN:VCALENDAR
BEGIN:VEVENT
UID:event-1@example.com
SUMMARY:Quiz 1
DESCRIPTION:View this quiz at https://brightspace.example.com/d2l/le/quiz/123
DTSTART:{$start->format('Ymd\THis\Z')}
DTEND:{$end->format('Ymd\THis\Z')}
END:VEVENT
END:VCALENDAR
ICS;

    Http::fake([
        $feed->feed_url => Http::response($ics, 200),
    ]);

    $service = app(CalendarFeedSyncService::class);

    $service->sync($feed);

    $task = Task::query()->where('user_id', $user->id)->first();

    expect($task)->not->toBeNull();
    expect($task->title)->toBe('Quiz 1');
    expect($task->description)->toBeNull();
    expect($task->teacher_name)->toBeNull();
    expect($task->subject_name)->toBeNull();
    expect($task->source_url)->toBe('https://brightspace.example.com/d2l/le/quiz/123');
    expect($task->calendar_feed_id)->toBe($feed->id);
    expect($task->source_type)->toBe(TaskSourceType::Brightspace);
    expect($task->source_id)->toBe('event-1@example.com');

    $feed->refresh();
    expect($feed->last_synced_at)->not->toBeNull();
});

it('maps vevent dates without forcing a start date', function () {
    $user = User::factory()->create();

    /** @var CalendarFeed $feed */
    $feed = CalendarFeed::query()->create([
        'user_id' => $user->id,
        'name' => 'Brightspace – All Courses',
        'feed_url' => 'https://example.test/calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
    ]);

    $start = now()->addDays(3)->setTime(9, 0)->utc();
    $end = $start->copy()->addHour();
    $onlyDueEnd = now()->addDays(5)->setTime(12, 0)->utc();
    $onlyDueStart = $onlyDueEnd->copy();

    $ics = <<<ICS
BEGIN:VCALENDAR
BEGIN:VEVENT
UID:with-start@example.com
SUMMARY:Has start and end
LOCATION:BCPAN_ALGORITHMS AND COMPLEXITY (UCOS 2-1)
DTSTART:{$start->format('Ymd\\THis\\Z')}
DTEND:{$end->format('Ymd\\THis\\Z')}
END:VEVENT
BEGIN:VEVENT
UID:only-due@example.com
SUMMARY:Only due
LOCATION:Emilio Aguinaldo College
DTSTART:{$onlyDueStart->format('Ymd\\THis\\Z')}
DTEND:{$onlyDueEnd->format('Ymd\\THis\\Z')}
END:VEVENT
END:VCALENDAR
ICS;

    Http::fake([
        $feed->feed_url => Http::response($ics, 200),
    ]);

    $service = app(CalendarFeedSyncService::class);

    $service->sync($feed);

    /** @var Task $taskWithStart */
    $taskWithStart = Task::query()
        ->where('user_id', $user->id)
        ->where('source_id', 'with-start@example.com')
        ->first();

    /** @var Task $taskOnlyDue */
    $taskOnlyDue = Task::query()
        ->where('user_id', $user->id)
        ->where('source_id', 'only-due@example.com')
        ->first();

    expect($taskWithStart)->not->toBeNull();
    expect($taskWithStart->start_datetime)->not->toBeNull();
    expect($taskWithStart->end_datetime)->not->toBeNull();
    expect($taskWithStart->end_datetime->greaterThan($taskWithStart->start_datetime))->toBeTrue();
    expect($taskWithStart->description)->toBeNull();
    expect($taskWithStart->teacher_name)->toBe('BCPAN');
    expect($taskWithStart->subject_name)->toBe('ALGORITHMS AND COMPLEXITY (UCOS 2-1)');

    expect($taskOnlyDue)->not->toBeNull();
    expect($taskOnlyDue->start_datetime)->toBeNull();
    expect($taskOnlyDue->end_datetime)->not->toBeNull();
    expect($taskOnlyDue->description)->toBeNull();
    expect($taskOnlyDue->teacher_name)->toBeNull();
    expect($taskOnlyDue->subject_name)->toBe('Emilio Aguinaldo College');
});

it('leaves source_url null when description has no URL', function () {
    $user = User::factory()->create();

    /** @var CalendarFeed $feed */
    $feed = CalendarFeed::query()->create([
        'user_id' => $user->id,
        'name' => 'Brightspace – All Courses',
        'feed_url' => 'https://example.test/calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
    ]);

    $start = now()->utc()->addDays(11)->setTime(9, 0, 0);
    $end = $start->copy()->addMinutes(30);
    $ics = <<<ICS
BEGIN:VCALENDAR
BEGIN:VEVENT
UID:event-2@example.com
SUMMARY:Assignment without link
DESCRIPTION:Review the assignment instructions in Brightspace.
DTSTART:{$start->format('Ymd\THis\Z')}
DTEND:{$end->format('Ymd\THis\Z')}
END:VEVENT
END:VCALENDAR
ICS;

    Http::fake([
        $feed->feed_url => Http::response($ics, 200),
    ]);

    $service = app(CalendarFeedSyncService::class);

    $service->sync($feed);

    /** @var Task $task */
    $task = Task::query()
        ->where('user_id', $user->id)
        ->where('source_id', 'event-2@example.com')
        ->first();

    expect($task)->not->toBeNull();
    expect($task->source_url)->toBeNull();
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

    $service = app(CalendarFeedSyncService::class);

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

    $service = app(CalendarFeedSyncService::class);

    $service->sync($feed);

    expect(Task::query()->whereKey($staleTask->id)->exists())->toBeTrue();
});

it('skips events that are entirely in the past', function () {
    $user = User::factory()->create();

    /** @var CalendarFeed $feed */
    $feed = CalendarFeed::query()->create([
        'user_id' => $user->id,
        'name' => 'Brightspace – All Courses',
        'feed_url' => 'https://example.test/calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
    ]);

    $start = now()->subMonths(7)->setTime(9, 0)->utc();
    $end = $start->copy()->addHour();

    $ics = <<<ICS
BEGIN:VCALENDAR
BEGIN:VEVENT
UID:past-event@example.com
SUMMARY:Old Assignment
DTSTART:{$start->format('Ymd\\THis\\Z')}
DTEND:{$end->format('Ymd\\THis\\Z')}
END:VEVENT
END:VCALENDAR
ICS;

    Http::fake([
        $feed->feed_url => Http::response($ics, 200),
    ]);

    $service = app(CalendarFeedSyncService::class);

    $service->sync($feed);

    expect(Task::query()->count())->toBe(0);

    $feed->refresh();
    expect($feed->last_synced_at)->not->toBeNull();
});

it('includes events that ended within the default calendar import window', function () {
    $user = User::factory()->create();

    /** @var CalendarFeed $feed */
    $feed = CalendarFeed::query()->create([
        'user_id' => $user->id,
        'name' => 'Brightspace – All Courses',
        'feed_url' => 'https://example.test/calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
    ]);

    $start = now()->subDays(30)->setTime(9, 0)->utc();
    $end = $start->copy()->addHour();

    $ics = <<<ICS
BEGIN:VCALENDAR
BEGIN:VEVENT
UID:recent-past-event@example.com
SUMMARY:Recent Assignment
DTSTART:{$start->format('Ymd\\THis\\Z')}
DTEND:{$end->format('Ymd\\THis\\Z')}
END:VEVENT
END:VCALENDAR
ICS;

    Http::fake([
        $feed->feed_url => Http::response($ics, 200),
    ]);

    $service = app(CalendarFeedSyncService::class);

    $service->sync($feed);

    expect(Task::query()->count())->toBe(1);
    $task = Task::query()->first();
    expect($task->title)->toBe('Recent Assignment');
});

it('skips events that ended before the rolling calendar-month import cutoff', function () {
    $user = User::factory()->create();

    /** @var CalendarFeed $feed */
    $feed = CalendarFeed::query()->create([
        'user_id' => $user->id,
        'name' => 'Brightspace – All Courses',
        'feed_url' => 'https://example.test/calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
    ]);

    $start = now()->subMonths(4)->setTime(9, 0)->utc();
    $end = $start->copy()->addHour();

    $ics = <<<ICS
BEGIN:VCALENDAR
BEGIN:VEVENT
UID:too-old@example.com
SUMMARY:Stale Assignment
DTSTART:{$start->format('Ymd\\THis\\Z')}
DTEND:{$end->format('Ymd\\THis\\Z')}
END:VEVENT
END:VCALENDAR
ICS;

    Http::fake([
        $feed->feed_url => Http::response($ics, 200),
    ]);

    $service = app(CalendarFeedSyncService::class);

    $service->sync($feed);

    expect(Task::query()->count())->toBe(0);

    $feed->refresh();
    expect($feed->last_synced_at)->not->toBeNull();
});

it('imports events within a user extended calendar import window', function () {
    $user = User::factory()->withCalendarImportPastMonths(6)->create();

    /** @var CalendarFeed $feed */
    $feed = CalendarFeed::query()->create([
        'user_id' => $user->id,
        'name' => 'Brightspace – All Courses',
        'feed_url' => 'https://example.test/calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
    ]);

    $start = now()->subMonths(5)->setTime(9, 0)->utc();
    $end = $start->copy()->addHour();

    $ics = <<<ICS
BEGIN:VCALENDAR
BEGIN:VEVENT
UID:extended-window@example.com
SUMMARY:Older but in window
DTSTART:{$start->format('Ymd\\THis\\Z')}
DTEND:{$end->format('Ymd\\THis\\Z')}
END:VEVENT
END:VCALENDAR
ICS;

    Http::fake([
        $feed->feed_url => Http::response($ics, 200),
    ]);

    $service = app(CalendarFeedSyncService::class);

    $service->sync($feed);

    expect(Task::query()->count())->toBe(1);
    expect(Task::query()->first()?->title)->toBe('Older but in window');
});

it('skips events outside a user extended calendar import window', function () {
    $user = User::factory()->withCalendarImportPastMonths(6)->create();

    /** @var CalendarFeed $feed */
    $feed = CalendarFeed::query()->create([
        'user_id' => $user->id,
        'name' => 'Brightspace – All Courses',
        'feed_url' => 'https://example.test/calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
    ]);

    $start = now()->subMonths(7)->setTime(9, 0)->utc();
    $end = $start->copy()->addHour();

    $ics = <<<ICS
BEGIN:VCALENDAR
BEGIN:VEVENT
UID:beyond-extended@example.com
SUMMARY:Too old for six months
DTSTART:{$start->format('Ymd\\THis\\Z')}
DTEND:{$end->format('Ymd\\THis\\Z')}
END:VEVENT
END:VCALENDAR
ICS;

    Http::fake([
        $feed->feed_url => Http::response($ics, 200),
    ]);

    $service = app(CalendarFeedSyncService::class);

    $service->sync($feed);

    expect(Task::query()->count())->toBe(0);
});

it('stores a completion inbox notification when notifyUserOnSuccess is true', function () {
    $user = User::factory()->create();

    /** @var CalendarFeed $feed */
    $feed = CalendarFeed::query()->create([
        'user_id' => $user->id,
        'name' => 'Brightspace – All Courses',
        'feed_url' => 'https://example.test/calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
    ]);

    $start = now()->utc()->addDays(10)->setTime(9, 0, 0);
    $end = $start->copy()->addMinutes(30);
    $ics = <<<ICS
BEGIN:VCALENDAR
BEGIN:VEVENT
UID:event-notify@example.com
SUMMARY:Quiz notify
DTSTART:{$start->format('Ymd\THis\Z')}
DTEND:{$end->format('Ymd\THis\Z')}
END:VEVENT
END:VCALENDAR
ICS;

    Http::fake([
        $feed->feed_url => Http::response($ics, 200),
    ]);

    $service = app(CalendarFeedSyncService::class);
    $service->sync($feed, true);

    $notification = $user->fresh()->notifications()->first();

    expect($notification)->not->toBeNull();
    expect($notification->type)->toBe(CalendarFeedSyncCompletedNotification::class);
    $data = $notification->data;
    expect($data['type'] ?? null)->toBe('calendar_feed_sync_completed');
    expect($data['meta']['items_applied'] ?? null)->toBe(1);
});

it('does not store a completion inbox notification when notifyUserOnSuccess is false', function () {
    $user = User::factory()->create();

    /** @var CalendarFeed $feed */
    $feed = CalendarFeed::query()->create([
        'user_id' => $user->id,
        'name' => 'Brightspace – All Courses',
        'feed_url' => 'https://example.test/calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
    ]);

    $start = now()->utc()->addDays(10)->setTime(9, 0, 0);
    $end = $start->copy()->addMinutes(30);
    $ics = <<<ICS
BEGIN:VCALENDAR
BEGIN:VEVENT
UID:event-no-notify@example.com
SUMMARY:Quiz no notify
DTSTART:{$start->format('Ymd\THis\Z')}
DTEND:{$end->format('Ymd\THis\Z')}
END:VEVENT
END:VCALENDAR
ICS;

    Http::fake([
        $feed->feed_url => Http::response($ics, 200),
    ]);

    $service = app(CalendarFeedSyncService::class);
    $service->sync($feed, false);

    expect($user->fresh()->notifications()->count())->toBe(0);
});

it('excludes overdue events when feed is configured to exclude overdue items', function () {
    $user = User::factory()->create();

    /** @var CalendarFeed $feed */
    $feed = CalendarFeed::query()->create([
        'user_id' => $user->id,
        'name' => 'Brightspace – All Courses',
        'feed_url' => 'https://example.test/calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
        'exclude_overdue_items' => true,
    ]);

    $pastStart = now()->subDays(2)->setTime(9, 0)->utc();
    $pastEnd = $pastStart->copy()->addHour();
    $futureStart = now()->addDays(2)->setTime(9, 0)->utc();
    $futureEnd = $futureStart->copy()->addHour();

    $ics = <<<ICS
BEGIN:VCALENDAR
BEGIN:VEVENT
UID:overdue-excluded@example.com
SUMMARY:Already overdue
DTSTART:{$pastStart->format('Ymd\\THis\\Z')}
DTEND:{$pastEnd->format('Ymd\\THis\\Z')}
END:VEVENT
BEGIN:VEVENT
UID:future-allowed@example.com
SUMMARY:Upcoming
DTSTART:{$futureStart->format('Ymd\\THis\\Z')}
DTEND:{$futureEnd->format('Ymd\\THis\\Z')}
END:VEVENT
END:VCALENDAR
ICS;

    Http::fake([
        $feed->feed_url => Http::response($ics, 200),
    ]);

    $service = app(CalendarFeedSyncService::class);
    $service->sync($feed);

    expect(Task::query()->where('source_id', 'overdue-excluded@example.com')->exists())->toBeFalse();
    expect(Task::query()->where('source_id', 'future-allowed@example.com')->exists())->toBeTrue();
});

it('includes overdue events when feed allows overdue items', function () {
    $user = User::factory()->create();

    /** @var CalendarFeed $feed */
    $feed = CalendarFeed::query()->create([
        'user_id' => $user->id,
        'name' => 'Brightspace – All Courses',
        'feed_url' => 'https://example.test/calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
        'exclude_overdue_items' => false,
    ]);

    $pastStart = now()->subDays(2)->setTime(9, 0)->utc();
    $pastEnd = $pastStart->copy()->addHour();

    $ics = <<<ICS
BEGIN:VCALENDAR
BEGIN:VEVENT
UID:overdue-allowed@example.com
SUMMARY:Already overdue
DTSTART:{$pastStart->format('Ymd\\THis\\Z')}
DTEND:{$pastEnd->format('Ymd\\THis\\Z')}
END:VEVENT
END:VCALENDAR
ICS;

    Http::fake([
        $feed->feed_url => Http::response($ics, 200),
    ]);

    $service = app(CalendarFeedSyncService::class);
    $service->sync($feed);

    expect(Task::query()->where('source_id', 'overdue-allowed@example.com')->exists())->toBeTrue();
});

it('uses feed import months instead of user-level fallback when feed setting exists', function () {
    $user = User::factory()->withCalendarImportPastMonths(1)->create();

    /** @var CalendarFeed $feed */
    $feed = CalendarFeed::query()->create([
        'user_id' => $user->id,
        'name' => 'Brightspace – All Courses',
        'feed_url' => 'https://example.test/calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
        'import_past_months' => 6,
    ]);

    $start = now()->subMonths(5)->setTime(9, 0)->utc();
    $end = $start->copy()->addHour();

    $ics = <<<ICS
BEGIN:VCALENDAR
BEGIN:VEVENT
UID:feed-specific-months@example.com
SUMMARY:Feed scoped lookback event
DTSTART:{$start->format('Ymd\\THis\\Z')}
DTEND:{$end->format('Ymd\\THis\\Z')}
END:VEVENT
END:VCALENDAR
ICS;

    Http::fake([
        $feed->feed_url => Http::response($ics, 200),
    ]);

    $service = app(CalendarFeedSyncService::class);
    $service->sync($feed);

    expect(Task::query()->where('source_id', 'feed-specific-months@example.com')->exists())->toBeTrue();
});
