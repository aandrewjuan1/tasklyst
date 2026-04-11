<?php

namespace App\Observers;

use App\Enums\CollaborationInviteNotificationState;
use App\Models\DatabaseNotification;
use App\Notifications\CollaborationInvitationReceivedNotification;

class DatabaseNotificationObserver
{
    public function created(DatabaseNotification $databaseNotification): void
    {
        if ($databaseNotification->type !== CollaborationInvitationReceivedNotification::class) {
            return;
        }

        if ($databaseNotification->collaboration_invite_state !== null) {
            return;
        }

        $databaseNotification->forceFill([
            'collaboration_invite_state' => CollaborationInviteNotificationState::Pending,
        ])->saveQuietly();
    }
}
