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
        $existing = CalendarFeed::query()
            ->where('user_id', $user->id)
            ->where('feed_url', $dto->feedUrl)
            ->first();

        if ($existing instanceof CalendarFeed) {
            $attributes = [
                'sync_enabled' => true,
            ];

            if ($dto->name !== null && $dto->name !== '') {
                $attributes['name'] = $dto->name;
            }

            if ($attributes !== []) {
                $existing = $this->calendarFeedService->updateFeed($existing, $attributes);
            }

            $this->calendarFeedSyncService->sync($existing);

            return $existing;
        }

        $feed = $this->calendarFeedService->createFeed($user, $dto->toServiceAttributes());

        $this->calendarFeedSyncService->sync($feed);

        return $feed;
    }
}
