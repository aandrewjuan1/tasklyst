<?php

namespace App\Actions\Notification;

use App\Models\User;

final class MarkNotificationUnreadForUserAction
{
    public function __construct(
        private FindOwnedDatabaseNotificationAction $findOwnedDatabaseNotification,
    ) {}

    public function execute(User $user, string $notificationId): bool
    {
        $notification = $this->findOwnedDatabaseNotification->execute($user, $notificationId);
        if ($notification === null) {
            return false;
        }

        $notification->update(['read_at' => null]);

        return true;
    }
}
