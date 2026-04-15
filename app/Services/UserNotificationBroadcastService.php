<?php

namespace App\Services;

use App\Events\UserNotificationCreated;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

final class UserNotificationBroadcastService
{
    public function __construct(
        private Dispatcher $events,
    ) {}

    public function broadcastInboxUpdated(User $user): void
    {
        try {
            $this->events->dispatch(new UserNotificationCreated(
                userId: (int) $user->id,
                unreadCount: (int) $user->unreadNotifications()->count(),
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
