<?php

namespace App\Services;

use App\Models\CalendarFeed;
use App\Models\User;

class CalendarFeedService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createFeed(User $user, array $attributes): CalendarFeed
    {
        return CalendarFeed::query()->create([
            ...$attributes,
            'user_id' => $user->id,
            'sync_enabled' => $attributes['sync_enabled'] ?? true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateFeed(CalendarFeed $feed, array $attributes): CalendarFeed
    {
        $feed->fill($attributes);
        $feed->save();

        return $feed;
    }

    public function deleteFeed(CalendarFeed $feed): bool
    {
        return (bool) $feed->delete();
    }
}
