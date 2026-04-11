<?php

namespace App\Actions\Notification;

use App\Models\User;
use Illuminate\Support\Carbon;

final class MarkAllUnreadNotificationsReadForUserAction
{
    /**
     * Mark every unread database notification for the user as read.
     */
    public function execute(User $user, ?Carbon $readAt = null): int
    {
        $readAt ??= now();

        return $user->notifications()
            ->whereNull('read_at')
            ->update(['read_at' => $readAt]);
    }
}
