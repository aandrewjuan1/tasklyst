<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class FocusSessionCompletedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $focusSessionId,
        public readonly ?int $taskId,
        public readonly int $durationSeconds,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $minutes = max(1, (int) round($this->durationSeconds / 60));

        return [
            'type' => 'focus_session_completed',
            'title' => __('Focus session completed'),
            'message' => __('Nice work. You completed a :minutes-minute session.', ['minutes' => $minutes]),
            'entity' => $this->taskId ? [
                'kind' => 'task',
                'id' => $this->taskId,
                'model' => Task::class,
            ] : null,
            'route' => 'workspace',
            'params' => array_filter([
                'view' => 'list',
                'type' => 'tasks',
                'task' => $this->taskId,
            ], fn (mixed $value): bool => $value !== null),
            'meta' => [
                'focus_session_id' => $this->focusSessionId,
                'duration_seconds' => $this->durationSeconds,
            ],
        ];
    }
}
