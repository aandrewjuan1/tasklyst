<?php

namespace App\Notifications;

use App\Models\CalendarFeed;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CalendarFeedStaleSyncNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $feedId,
        public readonly ?string $feedName = null,
        public readonly ?string $lastSyncedAt = null,
        public readonly int $staleHours = 6,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'calendar_feed_stale_sync',
            'title' => __('Calendar sync is stale'),
            'message' => __(':name has not synced in over :hours hours.', [
                'name' => $this->feedName ?: __('Calendar feed'),
                'hours' => $this->staleHours,
            ]),
            'entity' => [
                'kind' => 'calendar_feed',
                'id' => $this->feedId,
                'model' => CalendarFeed::class,
            ],
            'route' => 'workspace',
            'params' => [],
            'meta' => [
                'last_synced_at' => $this->lastSyncedAt,
            ],
        ];
    }
}
