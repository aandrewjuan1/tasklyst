<?php

namespace App\Actions\Notification;

use App\Models\DatabaseNotification;
use App\Models\User;

final class FindOwnedDatabaseNotificationAction
{
    public function execute(User $user, string $notificationId): ?DatabaseNotification
    {
        /** @var DatabaseNotification|null $notification */
        $notification = $user->notifications()->whereKey($notificationId)->first();

        return $notification;
    }
}
