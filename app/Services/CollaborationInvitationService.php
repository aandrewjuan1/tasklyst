<?php

namespace App\Services;

use App\Models\Collaboration;
use App\Models\CollaborationInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CollaborationInvitationService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createInvitation(array $attributes): CollaborationInvitation
    {
        return DB::transaction(function () use ($attributes): CollaborationInvitation {
            return CollaborationInvitation::query()->create($attributes);
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

            return Collaboration::query()->create([
                'collaboratable_type' => $invitation->collaboratable_type,
                'collaboratable_id' => $invitation->collaboratable_id,
                'user_id' => $invitation->invitee_user_id ?? $invitee?->id,
                'permission' => $invitation->permission,
            ]);
        });
    }
}
