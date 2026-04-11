<?php

namespace App\Actions\Notification;

use App\Models\User;
use Illuminate\Support\Carbon;

final class MarkVisibleNotificationsReadForUserAction
{
    /**
     * Mark as read any unread notifications in the same window as the bell popover (latest 10).
     */
    public function execute(User $user, ?Carbon $readAt = null): int
    {
        $readAt ??= now();

        $ids = $user->notifications()->latest()->limit(10)->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        return $user->notifications()
            ->whereIn('id', $ids)
            ->whereNull('read_at')
            ->update(['read_at' => $readAt]);
    }
}
