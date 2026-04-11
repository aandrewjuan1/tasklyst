<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;

final class NotificationBellState
{
    public static function resolveTargetUrl(DatabaseNotification $notification): string
    {
        $data = self::notificationDataAsArray($notification);
        $route = trim((string) ($data['route'] ?? ''));
        $params = is_array($data['params'] ?? null) ? $data['params'] : [];

        if ($route !== '' && Route::has($route)) {
            return route($route, $params);
        }

        return route('dashboard');
    }

    /**
     * @return array{
     *   notifications: array<int, array<string, mixed>>,
     *   unread_count: int,
     *   unread_label: string
     * }
     */
    public static function payloadForUser(User $user): array
    {
        $unreadCount = $user->unreadNotifications()->count();

        $notifications = $user->notifications()
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (DatabaseNotification $notification): array => self::normalizeNotification($notification))
            ->all();

        $unreadLabel = $unreadCount > 0
            ? trans_choice(':count unread', $unreadCount, ['count' => $unreadCount])
            : '';

        return [
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
            'unread_label' => $unreadLabel,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function notificationDataAsArray(DatabaseNotification $notification): array
    {
        $raw = $notification->data;
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @return array{
     *   id: string,
     *   title: string,
     *   message: string,
     *   route: string,
     *   params: array<string, mixed>,
     *   read_at: string|null,
     *   created_at_human: string
     * }
     */
    private static function normalizeNotification(DatabaseNotification $notification): array
    {
        $data = self::notificationDataAsArray($notification);
        $title = trim((string) ($data['title'] ?? ''));
        $message = trim((string) ($data['message'] ?? ''));
        $route = trim((string) ($data['route'] ?? ''));
        $params = is_array($data['params'] ?? null) ? $data['params'] : [];

        return [
            'id' => (string) $notification->id,
            'title' => $title !== '' ? $title : __('Notification'),
            'message' => $message,
            'route' => $route,
            'params' => $params,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at_human' => $notification->created_at?->diffForHumans() ?? __('Just now'),
        ];
    }
}
