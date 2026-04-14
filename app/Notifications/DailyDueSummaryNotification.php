<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DailyDueSummaryNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $date,
        public readonly int $tasksDueTodayCount,
        public readonly int $eventsTodayCount,
        public readonly int $overdueTasksCount,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'daily_due_summary',
            'title' => __('Today at a glance'),
            'message' => __(':tasks tasks due, :events events, :overdue overdue.', [
                'tasks' => $this->tasksDueTodayCount,
                'events' => $this->eventsTodayCount,
                'overdue' => $this->overdueTasksCount,
            ]),
            'route' => 'workspace',
            'params' => [
                'date' => $this->date,
                'view' => 'list',
            ],
            'meta' => [
                'tasks_due_today_count' => $this->tasksDueTodayCount,
                'events_today_count' => $this->eventsTodayCount,
                'overdue_tasks_count' => $this->overdueTasksCount,
            ],
        ];
    }
}
