<?php

namespace App\Notifications;

use App\Models\CalendarFeed;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CalendarFeedSyncCompletedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $feedId,
        public readonly ?string $feedName,
        public readonly int $itemsApplied,
        public readonly int $tasksCreated,
        public readonly int $tasksUpdated,
        public readonly int $eventsInWindow,
        public readonly int $eventsInRawFeed,
        public readonly int $eventsSkippedNoUid,
    ) {}

    /**
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
        $name = $this->feedName !== null && trim($this->feedName) !== '' ? trim($this->feedName) : __('Brightspace calendar');

        $title = __('Brightspace sync done');

        if ($this->itemsApplied > 0) {
            $message = trans_choice(
                '{1} :name · :count item synced.|[2,*] :name · :count items synced.',
                $this->itemsApplied,
                ['name' => $name, 'count' => $this->itemsApplied]
            );
            if ($this->tasksCreated > 0 || $this->tasksUpdated > 0) {
                $message .= ' '.__('(:created new, :updated updated).', [
                    'created' => $this->tasksCreated,
                    'updated' => $this->tasksUpdated,
                ]);
            }
        } else {
            $message = __(':name · synced; nothing new in range.', ['name' => $name]);
        }

        return [
            'type' => 'calendar_feed_sync_completed',
            'title' => $title,
            'message' => $message,
            'entity' => [
                'kind' => 'calendar_feed',
                'id' => $this->feedId,
                'model' => CalendarFeed::class,
            ],
            'route' => 'workspace',
            'params' => [],
            'meta' => [
                'feed_id' => $this->feedId,
                'feed_name' => $this->feedName,
                'items_applied' => $this->itemsApplied,
                'tasks_created' => $this->tasksCreated,
                'tasks_updated' => $this->tasksUpdated,
                'events_in_window' => $this->eventsInWindow,
                'events_in_raw_feed' => $this->eventsInRawFeed,
                'events_skipped_no_uid' => $this->eventsSkippedNoUid,
            ],
        ];
    }
}
