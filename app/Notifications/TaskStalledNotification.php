<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TaskStalledNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $taskId,
        public readonly string $taskTitle,
        public readonly int $hoursStalled,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'task_stalled',
            'title' => __('Task needs attention'),
            'message' => __('No activity for :hours hours: :title', [
                'hours' => $this->hoursStalled,
                'title' => '“'.$this->taskTitle.'”',
            ]),
            'entity' => [
                'kind' => 'task',
                'id' => $this->taskId,
                'model' => Task::class,
            ],
            'route' => 'workspace',
            'params' => [
                'view' => 'list',
                'type' => 'tasks',
                'task' => $this->taskId,
            ],
        ];
    }
}
