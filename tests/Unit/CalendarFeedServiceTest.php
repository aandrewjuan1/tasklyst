<?php

use App\Models\CalendarFeed;
use App\Models\User;
use App\Services\CalendarFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a calendar feed for a user', function () {
    $user = User::factory()->create();

    $service = new CalendarFeedService;

    /** @var CalendarFeed $feed */
    $feed = $service->createFeed($user, [
        'feed_url' => 'https://example.test/calendar.ics',
        'name' => 'Brightspace – All Courses',
        'source' => 'brightspace',
    ]);

    expect($feed->user_id)->toBe($user->id);
    expect($feed->feed_url)->toBe('https://example.test/calendar.ics');
    expect($feed->name)->toBe('Brightspace – All Courses');
    expect($feed->source)->toBe('brightspace');
    expect($feed->sync_enabled)->toBeTrue();
});

it('updates and deletes a calendar feed', function () {
    $user = User::factory()->create();

    $service = new CalendarFeedService;

    /** @var CalendarFeed $feed */
    $feed = $service->createFeed($user, [
        'feed_url' => 'https://example.test/calendar.ics',
        'name' => 'Original',
        'source' => 'brightspace',
    ]);

    $service->updateFeed($feed, ['name' => 'Updated']);

    $feed->refresh();
    expect($feed->name)->toBe('Updated');

    $deleted = $service->deleteFeed($feed);

    expect($deleted)->toBeTrue();
    expect(CalendarFeed::query()->find($feed->id))->toBeNull();
});
