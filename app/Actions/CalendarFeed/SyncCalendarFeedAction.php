<?php

namespace App\Actions\CalendarFeed;

use App\Models\CalendarFeed;
use App\Services\CalendarFeedSyncService;

class SyncCalendarFeedAction
{
    public function __construct(
        private CalendarFeedSyncService $calendarFeedSyncService
    ) {}

    public function execute(CalendarFeed $feed): void
    {
        $this->calendarFeedSyncService->sync($feed);
    }
}
