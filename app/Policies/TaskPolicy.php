<?php

namespace App\Policies;

use App\Enums\CollaborationPermission;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
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
    public function view(User $user, Task $task): bool
    {
        return $this->isOwner($user, $task) || $this->hasCollaborationAccess($user, $task);
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
    public function update(User $user, Task $task): bool
    {
        return $this->isOwner($user, $task) || $this->hasEditPermission($user, $task);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Task $task): bool
    {
        return $this->isOwner($user, $task);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Task $task): bool
    {
        return $this->isOwner($user, $task);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Task $task): bool
    {
        return $this->isOwner($user, $task);
    }

    /**
     * Check if the user is the owner of the task.
     */
    protected function isOwner(User $user, Task $task): bool
    {
        return $task->user_id === $user->id;
    }

    /**
     * Check if the user has collaboration access (view or edit).
     */
    protected function hasCollaborationAccess(User $user, Task $task): bool
    {
        return $task->collaborations()
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * Check if the user has edit permission as a collaborator.
     */
    protected function hasEditPermission(User $user, Task $task): bool
    {
        return $task->collaborations()
            ->where('user_id', $user->id)
            ->where('permission', CollaborationPermission::Edit)
            ->exists();
    }
}
