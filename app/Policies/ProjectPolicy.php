<?php

namespace App\Policies;

use App\Enums\CollaborationPermission;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
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
    public function view(User $user, Project $project): bool
    {
        return $this->isOwner($user, $project) || $this->hasCollaborationAccess($user, $project);
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
    public function update(User $user, Project $project): bool
    {
        return $this->isOwner($user, $project) || $this->hasEditPermission($user, $project);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Project $project): bool
    {
        return $this->isOwner($user, $project);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Project $project): bool
    {
        return $this->isOwner($user, $project);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Project $project): bool
    {
        return $this->isOwner($user, $project);
    }

    /**
     * Check if the user is the owner of the project.
     */
    protected function isOwner(User $user, Project $project): bool
    {
        return $project->user_id === $user->id;
    }

    /**
     * Check if the user has collaboration access (view or edit).
     */
    protected function hasCollaborationAccess(User $user, Project $project): bool
    {
        return $project->collaborations()
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * Check if the user has edit permission as a collaborator.
     */
    protected function hasEditPermission(User $user, Project $project): bool
    {
        return $project->collaborations()
            ->where('user_id', $user->id)
            ->where('permission', CollaborationPermission::Edit)
            ->exists();
    }
}
