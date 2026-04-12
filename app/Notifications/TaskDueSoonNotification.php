<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TaskDueSoonNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public readonly int $taskId,
        public readonly string $taskTitle,
        public readonly ?string $dueAtIso = null,
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
            ? __('Task due in :minutes min: :title', ['minutes' => $this->offsetMinutes, 'title' => '“'.$this->taskTitle.'”'])
            : __('Task due soon: :title', ['title' => '“'.$this->taskTitle.'”']);

        $date = null;
        if (is_string($this->dueAtIso) && $this->dueAtIso !== '') {
            try {
                $date = \Carbon\CarbonImmutable::parse($this->dueAtIso)->toDateString();
            } catch (\Throwable) {
                $date = null;
            }
        }

        return [
            'type' => 'task_due_soon',
            'title' => __('Due soon'),
            'message' => $message,
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
                'offset_minutes' => $this->offsetMinutes,
            ],
        ];
    }
}
