<?php

namespace App\Notifications;

use App\Models\CollaborationInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CollaborationInviteExpiringNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $invitationId,
        public readonly string $inviteeEmail,
        public readonly ?string $expiresAtIso,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'collaboration_invite_expiring',
            'title' => __('Invitation expiring soon'),
            'message' => __('Invite for :email is still pending.', ['email' => $this->inviteeEmail]),
            'entity' => [
                'kind' => 'collaboration_invitation',
                'id' => $this->invitationId,
                'model' => CollaborationInvitation::class,
            ],
            'route' => 'workspace',
            'params' => [],
            'meta' => [
                'expires_at' => $this->expiresAtIso,
            ],
        ];
    }
}
