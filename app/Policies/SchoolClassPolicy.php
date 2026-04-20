<?php

namespace App\Policies;

use App\Models\SchoolClass;
use App\Models\User;

class SchoolClassPolicy
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
    public function view(User $user, SchoolClass $schoolClass): bool
    {
        return $this->isOwner($user, $schoolClass);
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
    public function update(User $user, SchoolClass $schoolClass): bool
    {
        return $this->isOwner($user, $schoolClass);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SchoolClass $schoolClass): bool
    {
        return $this->isOwner($user, $schoolClass);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, SchoolClass $schoolClass): bool
    {
        return $this->isOwner($user, $schoolClass);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, SchoolClass $schoolClass): bool
    {
        return $this->isOwner($user, $schoolClass);
    }

    protected function isOwner(User $user, SchoolClass $schoolClass): bool
    {
        return $schoolClass->user_id === $user->id;
    }
}
