<?php

namespace App\Services;

use App\Events\UserNotificationCreated;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
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
            Log::warning('notifications.broadcast.dispatch_failed', [
                'layer' => 'broadcast',
                'user_id' => (int) $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
