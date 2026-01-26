<?php

namespace App\Policies;

use App\Enums\CollaborationPermission;
use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Event $event): bool
    {
        return $this->isOwner($user, $event) || $this->hasCollaborationAccess($user, $event);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Event $event): bool
    {
        return $this->isOwner($user, $event) || $this->hasEditPermission($user, $event);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Event $event): bool
    {
        return $this->isOwner($user, $event);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Event $event): bool
    {
        return $this->isOwner($user, $event);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Event $event): bool
    {
        return $this->isOwner($user, $event);
    }

    /**
     * Check if the user is the owner of the event.
     */
    protected function isOwner(User $user, Event $event): bool
    {
        return $event->user_id === $user->id;
    }

    /**
     * Check if the user has collaboration access (view or edit).
     */
    protected function hasCollaborationAccess(User $user, Event $event): bool
    {
        return $event->collaborations()
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * Check if the user has edit permission as a collaborator.
     */
    protected function hasEditPermission(User $user, Event $event): bool
    {
        return $event->collaborations()
            ->where('user_id', $user->id)
            ->where('permission', CollaborationPermission::Edit)
            ->exists();
    }
}
