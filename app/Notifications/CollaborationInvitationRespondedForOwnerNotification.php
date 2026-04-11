<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CollaborationInvitationRespondedForOwnerNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $workspaceParams
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly bool $accepted,
        public readonly string $inviteeDisplay,
        public readonly string $itemTitle,
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
        $type = $this->accepted
            ? 'collaboration_invite_accepted_for_owner'
            : 'collaboration_invite_declined_for_owner';

        if ($this->accepted) {
            $title = __('Invite accepted');
            $message = __(':name accepted your invitation to collaborate on “:item”.', [
                'name' => $this->inviteeDisplay,
                'item' => $this->itemTitle,
            ]);
        } else {
            $title = __('Invite declined');
            $message = __(':name declined your invitation to collaborate on “:item”.', [
                'name' => $this->inviteeDisplay,
                'item' => $this->itemTitle,
            ]);
        }

        return [
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'route' => 'workspace',
            'params' => $this->workspaceParams,
            'meta' => $this->meta,
        ];
    }
}
