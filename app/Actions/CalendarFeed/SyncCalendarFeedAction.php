<?php

namespace App\Actions\CalendarFeed;

use App\DataTransferObjects\CalendarFeed\CalendarFeedSyncResult;
use App\Enums\CalendarFeedSyncStatus;
use App\Jobs\SyncCalendarFeedJob;
use App\Models\CalendarFeed;
use App\Services\CalendarFeedSyncService;

class SyncCalendarFeedAction
{
    public function __construct(
        private CalendarFeedSyncService $calendarFeedSyncService
    ) {}

    public function execute(CalendarFeed $feed, bool $notifyUserOnSuccess = false, bool $queue = true): CalendarFeedSyncResult
    {
        if (! $feed->sync_enabled) {
            return new CalendarFeedSyncResult(CalendarFeedSyncStatus::SyncDisabled);
        }

        if ($queue) {
            SyncCalendarFeedJob::dispatch($feed->id, (int) $feed->user_id, $notifyUserOnSuccess);

            return new CalendarFeedSyncResult(CalendarFeedSyncStatus::Queued);
        }

        return $this->calendarFeedSyncService->sync($feed, $notifyUserOnSuccess);
    }
}
