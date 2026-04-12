<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TaskOverdueNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $taskId,
        public readonly string $taskTitle,
        public readonly ?string $dueAtIso = null,
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
        $date = null;
        if (is_string($this->dueAtIso) && $this->dueAtIso !== '') {
            try {
                $date = \Carbon\CarbonImmutable::parse($this->dueAtIso)->toDateString();
            } catch (\Throwable) {
                $date = null;
            }
        }

        return [
            'type' => 'task_overdue',
            'title' => __('Overdue'),
            'message' => __('Task overdue: :title', ['title' => '“'.$this->taskTitle.'”']),
            'entity' => [
                'kind' => 'task',
                'id' => $this->taskId,
                'model' => Task::class,
            ],
            'route' => 'workspace',
            'params' => array_filter([
                'date' => $date,
                'view' => 'list',
                'type' => 'tasks',
                'task' => $this->taskId,
            ], fn ($v) => $v !== null && $v !== ''),
            'meta' => [
                'due_at' => $this->dueAtIso,
            ],
        ];
    }
}
