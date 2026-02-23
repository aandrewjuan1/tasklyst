<?php

use App\Actions\CalendarFeed\ConnectCalendarFeedAction;
use App\DataTransferObjects\CalendarFeed\CreateCalendarFeedDto;
use App\Models\CalendarFeed;
use App\Models\User;
use App\Services\CalendarFeedService;
use App\Services\CalendarFeedSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('connects a feed and triggers initial sync', function () {
    $user = User::factory()->create();

    Http::fake([
        'https://example.test/calendar.ics' => Http::response("BEGIN:VCALENDAR\nEND:VCALENDAR", 200),
    ]);

    $service = new CalendarFeedService;
    $syncService = new CalendarFeedSyncService(new \App\Services\IcsParserService);

    $action = new ConnectCalendarFeedAction($service, $syncService);

    $dto = new CreateCalendarFeedDto(
        feedUrl: 'https://example.test/calendar.ics',
        name: 'Brightspace – All Courses',
        source: 'brightspace',
    );

    $feed = $action->execute($user, $dto);

    expect($feed)->toBeInstanceOf(CalendarFeed::class);
    expect($feed->user_id)->toBe($user->id);
    expect($feed->feed_url)->toBe('https://example.test/calendar.ics');
    expect($feed->last_synced_at)->not->toBeNull();
});
