<?php

namespace App\Actions\CalendarFeed;

use App\DataTransferObjects\CalendarFeed\CalendarFeedSyncResult;
use App\Models\CalendarFeed;
use App\Services\CalendarFeedSyncService;

class SyncCalendarFeedAction
{
    public function __construct(
        private CalendarFeedSyncService $calendarFeedSyncService
    ) {}

    public function execute(CalendarFeed $feed, bool $notifyUserOnSuccess = false): CalendarFeedSyncResult
    {
        return $this->calendarFeedSyncService->sync($feed, $notifyUserOnSuccess);
    }
}
