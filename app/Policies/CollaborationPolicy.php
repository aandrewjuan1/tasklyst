<?php

namespace App\Policies;

use App\Models\Collaboration;
use App\Models\User;

class CollaborationPolicy
{
    /**
     * Determine whether the user can update the collaboration (e.g. change permission).
     */
    public function update(User $user, Collaboration $collaboration): bool
    {
        $collaboratable = $collaboration->collaboratable;

        if ($collaboratable === null) {
            return false;
        }

        // Only the owner of the underlying item can manage collaborations.
        return (int) $collaboratable->user_id === (int) $user->id;
    }

    /**
     * Determine whether the user can remove the collaborator.
     */
    public function delete(User $user, Collaboration $collaboration): bool
    {
        $collaboratable = $collaboration->collaboratable;

        if ($collaboratable === null) {
            return false;
        }

        // Only the owner of the underlying item can remove collaborators.
        return (int) $collaboratable->user_id === (int) $user->id;
    }

    /**
     * Determine whether the user can remove their own collaboration (leave the item).
     */
    public function leave(User $user, Collaboration $collaboration): bool
    {
        return (int) $collaboration->user_id === (int) $user->id;
    }
}
