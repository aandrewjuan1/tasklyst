<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RecurrenceAnomalyNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $recurringKind,
        public readonly int $entityId,
        public readonly string $entityTitle,
        public readonly int $exceptionsCount,
        public readonly int $windowDays,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $kind = $this->recurringKind === 'event' ? 'event' : 'task';

        return [
            'type' => 'recurrence_anomaly',
            'title' => __('Recurring pattern anomaly'),
            'message' => __(':title has :count exceptions in :days days.', [
                'title' => $this->entityTitle !== '' ? $this->entityTitle : __('Recurring item'),
                'count' => $this->exceptionsCount,
                'days' => $this->windowDays,
            ]),
            'entity' => [
                'kind' => $kind,
                'id' => $this->entityId,
            ],
            'route' => 'workspace',
            'params' => [
                'view' => 'list',
                'type' => $kind === 'event' ? 'events' : 'tasks',
                $kind => $this->entityId,
            ],
        ];
    }
}
