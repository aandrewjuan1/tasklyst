<?php

namespace App\Actions\CalendarFeed;

use App\Models\CalendarFeed;
use App\Models\User;
use App\Services\CalendarFeedService;

class DisconnectCalendarFeedAction
{
    public function __construct(
        private CalendarFeedService $calendarFeedService
    ) {}

    public function execute(CalendarFeed $feed, ?User $actor = null): bool
    {
        return $this->calendarFeedService->deleteFeed($feed);
    }
}
