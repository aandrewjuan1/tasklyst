<?php

namespace App\Services;

use App\Enums\ActivityLogAction;
use App\Models\Collaboration;
use App\Models\CollaborationInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CollaborationInvitationService
{
    public function __construct(
        private ActivityLogRecorder $activityLogRecorder
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createInvitation(array $attributes): CollaborationInvitation
    {
        return DB::transaction(function () use ($attributes): CollaborationInvitation {
            $invitation = CollaborationInvitation::query()->create($attributes);
            $invitation->load(['collaboratable', 'inviter']);

            if ($invitation->collaboratable !== null && $invitation->inviter !== null) {
                $this->activityLogRecorder->record(
                    $invitation->collaboratable,
                    $invitation->inviter,
                    ActivityLogAction::CollaboratorInvited,
                    [
                        'invitee_email' => $invitation->invitee_email,
                        'permission' => $invitation->permission?->value,
                    ]
                );
            }

            return $invitation;
        });
    }

    public function markAccepted(CollaborationInvitation $invitation, ?User $invitee = null): Collaboration
    {
        return DB::transaction(function () use ($invitation, $invitee): Collaboration {
            if ($invitee !== null && $invitation->invitee_user_id === null) {
                $invitation->invitee_user_id = $invitee->id;
            }

            $invitation->status = 'accepted';
            $invitation->save();

            $collaboration = Collaboration::query()->create([
                'collaboratable_type' => $invitation->collaboratable_type,
                'collaboratable_id' => $invitation->collaboratable_id,
                'user_id' => $invitation->invitee_user_id ?? $invitee?->id,
                'permission' => $invitation->permission,
            ]);

            $invitation->load('collaboratable');
            if ($invitation->collaboratable !== null) {
                $actor = $invitee ?? $invitation->invitee;
                $this->activityLogRecorder->record(
                    $invitation->collaboratable,
                    $actor,
                    ActivityLogAction::CollaboratorInvitationAccepted,
                    [
                        'invitee_email' => $invitation->invitee_email,
                        'permission' => $invitation->permission?->value,
                    ]
                );
            }

            return $collaboration;
        });
    }
}
