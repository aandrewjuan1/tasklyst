<?php

namespace App\DataTransferObjects\CalendarFeed;

use App\Models\CalendarFeed;

final readonly class ConnectCalendarFeedResult
{
    public function __construct(
        public CalendarFeed $feed,
        public CalendarFeedSyncResult $sync,
    ) {}
}
