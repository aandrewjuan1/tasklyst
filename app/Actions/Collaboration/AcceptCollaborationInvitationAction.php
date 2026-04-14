<?php

namespace App\Actions\Collaboration;

use App\Models\Collaboration;
use App\Models\CollaborationInvitation;
use App\Models\User;
use App\Services\CollaborationInvitationService;

class AcceptCollaborationInvitationAction
{
    public function __construct(
        private CollaborationInvitationService $invitationService
    ) {}

    public function execute(CollaborationInvitation $invitation, User $user): ?Collaboration
    {
        if ($invitation->status !== 'pending') {
            return null;
        }

        if ($invitation->expires_at !== null && $invitation->expires_at->isPast()) {
            return null;
        }

        $emailMatches = strcasecmp($invitation->invitee_email, $user->email) === 0;
        $idMatches = $invitation->invitee_user_id !== null && $invitation->invitee_user_id === $user->id;

        if (! $emailMatches && ! $idMatches) {
            return null;
        }

        if ($invitation->collaboratable?->collaborations()
            ->where('user_id', $user->id)
            ->exists()) {
            $this->invitationService->finalizeAcceptedInvitation($invitation, $user);

            return null;
        }

        return $this->invitationService->markAccepted($invitation, $user);
    }
}
