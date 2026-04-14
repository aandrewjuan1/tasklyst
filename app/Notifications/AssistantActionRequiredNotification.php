<?php

namespace App\Notifications;

use App\Models\TaskAssistantThread;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AssistantActionRequiredNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $threadId,
        public readonly string $threadTitle,
        public readonly int $pendingProposalsCount,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'assistant_action_required',
            'title' => __('Assistant action required'),
            'message' => __('You have :count pending schedule proposals to review.', [
                'count' => $this->pendingProposalsCount,
            ]),
            'entity' => [
                'kind' => 'task_assistant_thread',
                'id' => $this->threadId,
                'model' => TaskAssistantThread::class,
            ],
            'route' => 'dashboard',
            'params' => [],
            'meta' => [
                'thread_title' => $this->threadTitle,
                'pending_proposals_count' => $this->pendingProposalsCount,
            ],
        ];
    }
}
