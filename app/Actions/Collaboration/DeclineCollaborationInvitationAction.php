<?php

namespace App\Actions\Collaboration;

use App\Enums\ActivityLogAction;
use App\Models\CollaborationInvitation;
use App\Models\User;
use App\Services\ActivityLogRecorder;

class DeclineCollaborationInvitationAction
{
    public function __construct(
        private ActivityLogRecorder $activityLogRecorder
    ) {}

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

        if (! $invitation->save()) {
            return false;
        }

        $invitation->load('collaboratable');
        if ($invitation->collaboratable !== null) {
            $this->activityLogRecorder->record(
                $invitation->collaboratable,
                $user,
                ActivityLogAction::CollaboratorInvitationDeclined,
                ['invitee_email' => $invitation->invitee_email]
            );
        }

        return true;
    }
}
