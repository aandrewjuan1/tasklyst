<?php

namespace App\Jobs;

use App\Models\CalendarFeed;
use App\Services\CalendarFeedSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncCalendarFeedJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(
        public int $calendarFeedId,
        public int $userId,
        public bool $notifyUserOnSuccess,
    ) {}

    public function handle(CalendarFeedSyncService $calendarFeedSyncService): void
    {
        $feed = CalendarFeed::query()
            ->whereKey($this->calendarFeedId)
            ->where('user_id', $this->userId)
            ->first();

        if (! $feed instanceof CalendarFeed) {
            return;
        }

        $calendarFeedSyncService->sync($feed, $this->notifyUserOnSuccess);
    }
}
