<?php

namespace App\Services;

use App\Models\CollaborationInvitation;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\CollaborationInvitationRespondedForOwnerNotification;
use App\Support\WorkspaceNotificationParams;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class CollaborationInvitationOwnerResponseNotifier
{
    public function __construct(
        private UserNotificationBroadcastService $userNotificationBroadcastService,
    ) {}

    public function notifyInviterOfResponse(
        CollaborationInvitation $invitation,
        bool $accepted,
        ?User $respondingUser = null,
    ): void {
        $run = function () use ($invitation, $accepted, $respondingUser): void {
            $invitation->loadMissing(['collaboratable', 'inviter', 'invitee']);
            $inviter = $invitation->inviter;
            $collaboratable = $invitation->collaboratable;

            if (! $inviter instanceof User || ! $this->isSupportedCollaboratable($collaboratable)) {
                return;
            }

            $actor = $respondingUser ?? $invitation->invitee;
            $inviteeDisplay = $actor?->name
                ?? $actor?->email
                ?? (string) $invitation->invitee_email;

            $itemTitle = WorkspaceNotificationParams::itemTitle($collaboratable);
            if ($itemTitle === '') {
                $itemTitle = __('Untitled');
            }

            $workspaceParams = WorkspaceNotificationParams::forLoggable($collaboratable);
            if ($workspaceParams === []) {
                return;
            }

            $inviter->notify(new CollaborationInvitationRespondedForOwnerNotification(
                accepted: $accepted,
                inviteeDisplay: $inviteeDisplay,
                itemTitle: $itemTitle,
                workspaceParams: $workspaceParams,
                meta: [
                    'invitation_id' => $invitation->id,
                    'collaboratable_type' => $invitation->collaboratable_type,
                    'collaboratable_id' => $invitation->collaboratable_id,
                    'invitee_user_id' => $invitation->invitee_user_id,
                    'invitee_email' => $invitation->invitee_email,
                    'permission' => $invitation->permission?->value,
                    'item_kind' => WorkspaceNotificationParams::itemKindLabel($collaboratable),
                ],
            ));

            $this->userNotificationBroadcastService->broadcastInboxUpdated($inviter);
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($run);
        } else {
            $run();
        }
    }

    private function isSupportedCollaboratable(?Model $collaboratable): bool
    {
        return $collaboratable instanceof Task
            || $collaboratable instanceof Project
            || $collaboratable instanceof Event;
    }
}
