<?php

namespace App\Notifications;

use App\Models\CalendarFeed;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CalendarFeedSyncFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $feedId,
        public readonly ?string $feedName = null,
        public readonly ?string $reason = null,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $name = $this->feedName !== null && trim($this->feedName) !== '' ? trim($this->feedName) : __('Calendar feed');

        return [
            'type' => 'calendar_feed_sync_failed',
            'title' => __('Calendar sync failed'),
            'message' => __('We couldn’t sync :name. Please check your feed settings.', ['name' => $name]),
            'entity' => [
                'kind' => 'calendar_feed',
                'id' => $this->feedId,
                'model' => CalendarFeed::class,
            ],
            'route' => 'workspace',
            'params' => [],
            'meta' => [
                'reason' => $this->reason,
            ],
        ];
    }
}
