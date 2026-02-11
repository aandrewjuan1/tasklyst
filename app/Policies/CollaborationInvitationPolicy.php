<?php

namespace App\Policies;

use App\Models\CollaborationInvitation;
use App\Models\User;

class CollaborationInvitationPolicy
{
    /**
     * Determine whether the user can cancel (delete) the invitation.
     */
    public function delete(User $user, CollaborationInvitation $invitation): bool
    {
        $collaboratable = $invitation->collaboratable;

        return $collaboratable !== null && $user->can('update', $collaboratable);
    }

    /**
     * Determine whether the user can accept the invitation.
     */
    public function accept(User $user, CollaborationInvitation $invitation): bool
    {
        if ($invitation->status !== 'pending') {
            return false;
        }

        if ($invitation->expires_at !== null && $invitation->expires_at->isPast()) {
            return false;
        }

        $emailMatches = strcasecmp($invitation->invitee_email, $user->email) === 0;
        $idMatches = $invitation->invitee_user_id !== null && $invitation->invitee_user_id === $user->id;

        return $emailMatches || $idMatches;
    }

    /**
     * Determine whether the user can decline the invitation.
     */
    public function decline(User $user, CollaborationInvitation $invitation): bool
    {
        if ($invitation->status !== 'pending') {
            return false;
        }

        $emailMatches = strcasecmp($invitation->invitee_email, $user->email) === 0;
        $idMatches = $invitation->invitee_user_id !== null && $invitation->invitee_user_id === $user->id;

        return $emailMatches || $idMatches;
    }
}
