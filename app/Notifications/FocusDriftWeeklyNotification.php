<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class FocusDriftWeeklyNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $weekStart,
        public readonly string $weekEnd,
        public readonly int $plannedSeconds,
        public readonly int $completedSeconds,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $plannedMinutes = (int) floor($this->plannedSeconds / 60);
        $completedMinutes = (int) floor($this->completedSeconds / 60);

        return [
            'type' => 'focus_drift_weekly',
            'title' => __('Weekly focus report'),
            'message' => __('Completed :completed/:planned minutes last week.', [
                'completed' => $completedMinutes,
                'planned' => max(1, $plannedMinutes),
            ]),
            'route' => 'dashboard',
            'params' => [],
            'meta' => [
                'week_start' => $this->weekStart,
                'week_end' => $this->weekEnd,
                'planned_seconds' => $this->plannedSeconds,
                'completed_seconds' => $this->completedSeconds,
            ],
        ];
    }
}
