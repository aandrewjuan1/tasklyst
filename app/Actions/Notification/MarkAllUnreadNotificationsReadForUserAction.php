<?php

namespace App\Actions\Notification;

use App\Models\User;
use App\Services\UserNotificationBroadcastService;
use Illuminate\Support\Carbon;

final class MarkAllUnreadNotificationsReadForUserAction
{
    public function __construct(
        private UserNotificationBroadcastService $userNotificationBroadcastService,
    ) {}

    /**
     * Mark every unread database notification for the user as read.
     */
    public function execute(User $user, ?Carbon $readAt = null): int
    {
        $readAt ??= now();

        $updated = $user->notifications()
            ->whereNull('read_at')
            ->update(['read_at' => $readAt]);

        if ($updated > 0) {
            $this->userNotificationBroadcastService->broadcastInboxUpdated($user);
        }

        return $updated;
    }
}
