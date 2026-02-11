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

        return $collaboratable !== null && $user->can('update', $collaboratable);
    }

    /**
     * Determine whether the user can remove the collaborator.
     */
    public function delete(User $user, Collaboration $collaboration): bool
    {
        $collaboratable = $collaboration->collaboratable;

        return $collaboratable !== null && $user->can('update', $collaboratable);
    }
}
