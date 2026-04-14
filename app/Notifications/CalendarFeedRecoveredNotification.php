<?php

namespace App\Notifications;

use App\Models\CalendarFeed;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CalendarFeedRecoveredNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $feedId,
        public readonly ?string $feedName = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'calendar_feed_recovered',
            'title' => __('Calendar sync recovered'),
            'message' => __('Sync resumed for :name.', ['name' => $this->feedName ?: __('Calendar feed')]),
            'entity' => [
                'kind' => 'calendar_feed',
                'id' => $this->feedId,
                'model' => CalendarFeed::class,
            ],
            'route' => 'workspace',
            'params' => [],
        ];
    }
}
