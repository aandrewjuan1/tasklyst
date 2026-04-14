<?php

namespace App\Actions\Notification;

use App\Models\User;
use App\Services\UserNotificationBroadcastService;
use Illuminate\Support\Carbon;

final class MarkVisibleNotificationsReadForUserAction
{
    public function __construct(
        private UserNotificationBroadcastService $userNotificationBroadcastService,
    ) {}

    /**
     * Mark as read any unread notifications whose IDs are currently shown in the bell panel.
     *
     * @param  array<int, string>  $notificationIds
     */
    public function execute(User $user, array $notificationIds, ?Carbon $readAt = null): int
    {
        if ($notificationIds === []) {
            return 0;
        }

        $readAt ??= now();

        $updated = $user->notifications()
            ->whereIn('id', $notificationIds)
            ->whereNull('read_at')
            ->update(['read_at' => $readAt]);

        if ($updated > 0) {
            $this->userNotificationBroadcastService->broadcastInboxUpdated($user);
        }

        return $updated;
    }
}
