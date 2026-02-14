<?php

namespace App\Policies;

use App\Models\FocusSession;
use App\Models\User;

class FocusSessionPolicy
{
    /**
     * Determine whether the user can view any focus sessions.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the focus session.
     */
    public function view(User $user, FocusSession $focusSession): bool
    {
        return (int) $focusSession->user_id === (int) $user->id;
    }

    /**
     * Determine whether the user can update the focus session.
     */
    public function update(User $user, FocusSession $focusSession): bool
    {
        return (int) $focusSession->user_id === (int) $user->id;
    }

    /**
     * Determine whether the user can delete the focus session.
     */
    public function delete(User $user, FocusSession $focusSession): bool
    {
        return (int) $focusSession->user_id === (int) $user->id;
    }
}
