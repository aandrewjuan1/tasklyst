<?php

namespace App\Notifications;

use App\Models\CollaborationInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CollaborationInvitationReceivedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $invitationId,
        public readonly string $inviteeEmail,
        public readonly string $collaboratableType,
        public readonly int $collaboratableId,
        public readonly ?string $permission = null,
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
        return [
            'type' => 'collaboration_invite_received',
            'title' => __('Collaboration invite'),
            'message' => __('You received a collaboration invitation.'),
            'entity' => [
                'kind' => 'collaboration_invitation',
                'id' => $this->invitationId,
                'model' => CollaborationInvitation::class,
            ],
            'route' => 'workspace',
            'params' => [],
            'meta' => [
                'invitee_email' => $this->inviteeEmail,
                'collaboratable_type' => $this->collaboratableType,
                'collaboratable_id' => $this->collaboratableId,
                'permission' => $this->permission,
            ],
        ];
    }
}
