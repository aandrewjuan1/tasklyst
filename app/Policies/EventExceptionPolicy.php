<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\EventException;
use App\Models\User;

class EventExceptionPolicy
{
    /**
     * Determine whether the user can view any event exceptions.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the event exception.
     */
    public function view(User $user, EventException $eventException): bool
    {
        $event = $this->resolveEvent($eventException);

        return $event !== null && $user->can('update', $event);
    }

    /**
     * Determine whether the user can update the event exception.
     */
    public function update(User $user, EventException $eventException): bool
    {
        $event = $this->resolveEvent($eventException);

        return $event !== null && $user->can('update', $event);
    }

    /**
     * Determine whether the user can delete the event exception.
     */
    public function delete(User $user, EventException $eventException): bool
    {
        $event = $this->resolveEvent($eventException);

        return $event !== null && $user->can('update', $event);
    }

    protected function resolveEvent(EventException $eventException): ?Event
    {
        $eventException->loadMissing('recurringEvent.event');

        return $eventException->recurringEvent?->event;
    }
}
