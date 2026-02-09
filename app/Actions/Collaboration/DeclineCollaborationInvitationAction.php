<?php

namespace App\Actions\Collaboration;

use App\Models\CollaborationInvitation;
use App\Models\User;

class DeclineCollaborationInvitationAction
{
    public function execute(CollaborationInvitation $invitation, User $user): bool
    {
        if ($invitation->status !== 'pending') {
            return false;
        }

        $emailMatches = strcasecmp($invitation->invitee_email, $user->email) === 0;
        $idMatches = $invitation->invitee_user_id !== null && $invitation->invitee_user_id === $user->id;

        if (! $emailMatches && ! $idMatches) {
            return false;
        }

        $invitation->status = 'declined';
        $invitation->invitee_user_id = $invitation->invitee_user_id ?? $user->id;

        return (bool) $invitation->save();
    }
}
