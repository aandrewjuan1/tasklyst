<?php

namespace App\Policies;

use App\Models\CalendarFeed;
use App\Models\User;

class CalendarFeedPolicy
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
    public function view(User $user, CalendarFeed $calendarFeed): bool
    {
        return $this->isOwner($user, $calendarFeed);
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
    public function update(User $user, CalendarFeed $calendarFeed): bool
    {
        return $this->isOwner($user, $calendarFeed);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, CalendarFeed $calendarFeed): bool
    {
        return $this->isOwner($user, $calendarFeed);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, CalendarFeed $calendarFeed): bool
    {
        return $this->isOwner($user, $calendarFeed);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, CalendarFeed $calendarFeed): bool
    {
        return $this->isOwner($user, $calendarFeed);
    }

    protected function isOwner(User $user, CalendarFeed $calendarFeed): bool
    {
        return $calendarFeed->user_id === $user->id;
    }
}
