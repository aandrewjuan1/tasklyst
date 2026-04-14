<?php

namespace App\Actions\Notification;

use App\Models\User;
use App\Services\UserNotificationBroadcastService;

final class MarkNotificationReadForUserAction
{
    public function __construct(
        private FindOwnedDatabaseNotificationAction $findOwnedDatabaseNotification,
        private UserNotificationBroadcastService $userNotificationBroadcastService,
    ) {}

    public function execute(User $user, string $notificationId): bool
    {
        $notification = $this->findOwnedDatabaseNotification->execute($user, $notificationId);
        if ($notification === null) {
            return false;
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
            $this->userNotificationBroadcastService->broadcastInboxUpdated($user);
        }

        return true;
    }
}
