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
        'https://example.test/calendar-again.ics' => Http::response("BEGIN:VCALENDAR\nEND:VCALENDAR", 200),
    ]);

    $service = new CalendarFeedService;
    $syncService = app(CalendarFeedSyncService::class);

    $action = new ConnectCalendarFeedAction($service, $syncService);

    $dto = new CreateCalendarFeedDto(
        feedUrl: 'https://example.test/calendar.ics',
        name: 'Brightspace – All Courses',
        source: 'brightspace',
    );

    $result = $action->execute($user, $dto);

    expect($result->feed)->toBeInstanceOf(CalendarFeed::class);
    expect($result->feed->user_id)->toBe($user->id);
    expect($result->feed->feed_url)->toBe('https://example.test/calendar.ics');
    expect($result->feed->last_synced_at)->not->toBeNull();
});

it('reuses existing feed and resyncs when connecting the same url again', function () {
    $user = User::factory()->create();

    Http::fake([
        'https://example.test/calendar.ics' => Http::response("BEGIN:VCALENDAR\nEND:VCALENDAR", 200),
    ]);

    $service = new CalendarFeedService;
    $syncService = app(CalendarFeedSyncService::class);

    $action = new ConnectCalendarFeedAction($service, $syncService);

    $dto = new CreateCalendarFeedDto(
        feedUrl: 'https://example.test/calendar.ics',
        name: 'Brightspace – All Courses',
        source: 'brightspace',
    );

    $firstResult = $action->execute($user, $dto);
    $firstId = $firstResult->feed->id;

    // Change the name to ensure it can be updated on reconnect
    $secondDto = new CreateCalendarFeedDto(
        feedUrl: 'https://example.test/calendar.ics',
        name: 'Updated Name',
        source: 'brightspace',
    );

    $secondResult = $action->execute($user, $secondDto);

    $secondResult->feed->refresh();

    expect($secondResult->feed->id)->toBe($firstId)
        ->and($secondResult->feed->name)->toBe('Updated Name');

    expect(CalendarFeed::query()->where('user_id', $user->id)->count())->toBe(1);
});
