<?php

namespace App\Actions\Notification;

use App\Models\User;
use Illuminate\Support\Carbon;

final class MarkVisibleNotificationsReadForUserAction
{
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

        return $user->notifications()
            ->whereIn('id', $notificationIds)
            ->whereNull('read_at')
            ->update(['read_at' => $readAt]);
    }
}
