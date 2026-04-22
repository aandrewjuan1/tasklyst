<?php

namespace App\Notifications;

use App\Models\TaskAssistantThread;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AssistantResponseReadyNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $threadId,
        public readonly int $assistantMessageId,
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
        return [
            'type' => 'assistant_response_ready',
            'title' => __('Assistant response ready'),
            'message' => __('Your task assistant response is ready to review.'),
            'entity' => [
                'kind' => 'task_assistant_thread',
                'id' => $this->threadId,
                'model' => TaskAssistantThread::class,
            ],
            'route' => 'dashboard',
            'params' => [],
            'meta' => [
                'thread_id' => $this->threadId,
                'assistant_message_id' => $this->assistantMessageId,
            ],
        ];
    }
}
