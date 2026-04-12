<?php

namespace App\Notifications;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class EventStartSoonNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $eventId,
        public readonly string $eventTitle,
        public readonly ?string $startAtIso = null,
        public readonly ?int $offsetMinutes = null,
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
        $message = $this->offsetMinutes !== null && $this->offsetMinutes > 0
            ? __('Event starts in :minutes min: :title', ['minutes' => $this->offsetMinutes, 'title' => '“'.$this->eventTitle.'”'])
            : __('Event starting soon: :title', ['title' => '“'.$this->eventTitle.'”']);

        $date = null;
        if (is_string($this->startAtIso) && $this->startAtIso !== '') {
            try {
                $date = \Carbon\CarbonImmutable::parse($this->startAtIso)->toDateString();
            } catch (\Throwable) {
                $date = null;
            }
        }

        return [
            'type' => 'event_start_soon',
            'title' => __('Starting soon'),
            'message' => $message,
            'entity' => [
                'kind' => 'event',
                'id' => $this->eventId,
                'model' => Event::class,
            ],
            'route' => 'workspace',
            'params' => array_filter([
                'date' => $date,
                'view' => 'list',
                'type' => 'events',
                'event' => $this->eventId,
            ], fn ($v) => $v !== null && $v !== ''),
            'meta' => [
                'start_at' => $this->startAtIso,
                'offset_minutes' => $this->offsetMinutes,
            ],
        ];
    }
}
