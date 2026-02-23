<?php

use App\Enums\TaskSourceType;
use App\Models\CalendarFeed;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('syncs enabled calendar feeds via console command', function () {
    $user = User::factory()->create();

    /** @var CalendarFeed $feed */
    $feed = CalendarFeed::query()->create([
        'user_id' => $user->id,
        'feed_url' => 'https://example.test/calendar.ics',
        'name' => 'Brightspace – All Courses',
        'source' => 'brightspace',
        'sync_enabled' => true,
    ]);

    $ics = <<<'ICS'
BEGIN:VCALENDAR
BEGIN:VEVENT
UID:cmd-event@example.com
SUMMARY:Exam via Command
DTSTART:20260301T120000Z
DTEND:20260301T130000Z
END:VEVENT
END:VCALENDAR
ICS;

    Http::fake([
        $feed->feed_url => Http::response($ics, 200),
    ]);

    Artisan::call('calendar:sync-feeds');

    $task = Task::query()
        ->where('user_id', $user->id)
        ->where('source_type', TaskSourceType::Brightspace)
        ->where('source_id', 'cmd-event@example.com')
        ->first();

    expect($task)->not->toBeNull();
    expect($task->title)->toBe('Exam via Command');

    $feed->refresh();
    expect($feed->last_synced_at)->not->toBeNull();
});
