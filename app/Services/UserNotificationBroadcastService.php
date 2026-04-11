<?php

namespace App\Services;

use App\Events\UserNotificationCreated;
use App\Models\User;

final class UserNotificationBroadcastService
{
    public function broadcastInboxUpdated(User $user): void
    {
        event(new UserNotificationCreated(
            userId: (int) $user->id,
            unreadCount: (int) $user->unreadNotifications()->count(),
        ));
    }
}
