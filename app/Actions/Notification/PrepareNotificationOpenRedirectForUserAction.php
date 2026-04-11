<?php

namespace App\Actions\Notification;

use App\Models\User;
use App\Support\NotificationBellState;

final class PrepareNotificationOpenRedirectForUserAction
{
    public function __construct(
        private FindOwnedDatabaseNotificationAction $findOwnedDatabaseNotification,
    ) {}

    public function execute(User $user, string $notificationId): ?string
    {
        $notification = $this->findOwnedDatabaseNotification->execute($user, $notificationId);
        if ($notification === null) {
            return null;
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return NotificationBellState::resolveTargetUrl($notification->fresh());
    }
}
