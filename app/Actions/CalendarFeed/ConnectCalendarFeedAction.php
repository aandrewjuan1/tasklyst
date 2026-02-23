<?php

namespace App\Actions\CalendarFeed;

use App\DataTransferObjects\CalendarFeed\CreateCalendarFeedDto;
use App\Models\CalendarFeed;
use App\Models\User;
use App\Services\CalendarFeedService;
use App\Services\CalendarFeedSyncService;

class ConnectCalendarFeedAction
{
    public function __construct(
        private CalendarFeedService $calendarFeedService,
        private CalendarFeedSyncService $calendarFeedSyncService
    ) {}

    public function execute(User $user, CreateCalendarFeedDto $dto): CalendarFeed
    {
        $feed = $this->calendarFeedService->createFeed($user, $dto->toServiceAttributes());

        $this->calendarFeedSyncService->sync($feed);

        return $feed;
    }
}
