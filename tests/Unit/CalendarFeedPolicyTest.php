<?php

use App\Models\CalendarFeed;
use App\Models\User;
use App\Policies\CalendarFeedPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows owner to manage their calendar feeds', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $feed = CalendarFeed::query()->create([
        'user_id' => $owner->id,
        'feed_url' => 'https://example.test/calendar.ics',
        'name' => 'My Feed',
        'source' => 'brightspace',
        'sync_enabled' => true,
    ]);

    $policy = new CalendarFeedPolicy;

    expect($policy->viewAny($owner))->toBeTrue();
    expect($policy->create($owner))->toBeTrue();

    expect($policy->view($owner, $feed))->toBeTrue();
    expect($policy->update($owner, $feed))->toBeTrue();
    expect($policy->delete($owner, $feed))->toBeTrue();
    expect($policy->restore($owner, $feed))->toBeTrue();
    expect($policy->forceDelete($owner, $feed))->toBeTrue();

    expect($policy->view($other, $feed))->toBeFalse();
    expect($policy->update($other, $feed))->toBeFalse();
    expect($policy->delete($other, $feed))->toBeFalse();
});
