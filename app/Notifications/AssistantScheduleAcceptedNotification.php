<?php

namespace App\Notifications;

use App\Models\TaskAssistantThread;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AssistantScheduleAcceptedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $threadId,
        public readonly int $assistantMessageId,
        public readonly int $acceptedCount,
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
            'type' => 'assistant_schedule_accept_success',
            'title' => __('Schedule accepted'),
            'message' => trans_choice(
                'Accepted :count schedule proposal.|Accepted :count schedule proposals.',
                $this->acceptedCount,
                ['count' => $this->acceptedCount]
            ),
            'entity' => [
                'kind' => 'task_assistant_thread',
                'id' => $this->threadId,
                'model' => TaskAssistantThread::class,
            ],
            'route' => 'workspace',
            'params' => [],
            'meta' => [
                'thread_id' => $this->threadId,
                'assistant_message_id' => $this->assistantMessageId,
                'accepted_count' => $this->acceptedCount,
            ],
        ];
    }
}
