<?php

namespace App\Actions\Collaboration;

use App\Enums\ActivityLogAction;
use App\Enums\CollaborationInviteNotificationState;
use App\Enums\ReminderType;
use App\Models\CollaborationInvitation;
use App\Models\User;
use App\Services\ActivityLogRecorder;
use App\Services\CollaborationInvitationOwnerResponseNotifier;
use App\Services\CollaborationInvitationService;
use App\Services\Reminders\ReminderSchedulerService;

class DeclineCollaborationInvitationAction
{
    public function __construct(
        private ActivityLogRecorder $activityLogRecorder,
        private CollaborationInvitationService $collaborationInvitationService,
        private CollaborationInvitationOwnerResponseNotifier $collaborationInvitationOwnerResponseNotifier,
        private ReminderSchedulerService $reminderSchedulerService,
    ) {}

    public function execute(CollaborationInvitation $invitation, User $user): bool
    {
        if ($invitation->status !== 'pending') {
            return false;
        }

        if ($invitation->expires_at !== null && $invitation->expires_at->isPast()) {
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

        $this->reminderSchedulerService->cancelForRemindable($invitation, ReminderType::CollaborationInviteReceived);
        $this->collaborationInvitationService->markInviteNotificationsHandled($invitation, CollaborationInviteNotificationState::Declined);

        $invitation->load('collaboratable');
        if ($invitation->collaboratable !== null) {
            $this->activityLogRecorder->record(
                $invitation->collaboratable,
                $user,
                ActivityLogAction::CollaboratorInvitationDeclined,
                ['invitee_email' => $invitation->invitee_email]
            );
        }

        $this->collaborationInvitationOwnerResponseNotifier->notifyInviterOfResponse($invitation, false, $user);

        return true;
    }
}
