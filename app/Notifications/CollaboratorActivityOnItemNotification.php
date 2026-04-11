<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CollaboratorActivityOnItemNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $workspaceParams
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $title,
        public readonly string $message,
        public readonly array $workspaceParams,
        public readonly array $meta = [],
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
            'type' => 'collaborator_activity',
            'title' => $this->title,
            'message' => $this->message,
            'route' => 'workspace',
            'params' => $this->workspaceParams,
            'meta' => $this->meta,
        ];
    }
}
