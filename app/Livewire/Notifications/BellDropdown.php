<?php

namespace App\Livewire\Notifications;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class BellDropdown extends Component
{
    #[Locked]
    public int $userId = 0;

    public int $unreadCount = 0;

    /**
     * @var array<int, array{
     *   id: string,
     *   title: string,
     *   message: string,
     *   route: string,
     *   params: array<string, mixed>,
     *   read_at: string|null,
     *   created_at_human: string
     * }>
     */
    public array $notifications = [];

    public function mount(): void
    {
        $this->userId = (int) Auth::id();
        $this->refreshBadge();
        $this->refreshList();
    }

    #[On('echo-private:App.Models.User.{userId},.notification_created')]
    public function onNotificationCreated(): void
    {
        $this->refreshBadge();
    }

    public function refreshBadge(): void
    {
        $user = Auth::user();
        if ($user === null) {
            $this->unreadCount = 0;

            return;
        }

        $this->unreadCount = $user->unreadNotifications()->count();
    }

    public function refreshList(): void
    {
        $user = Auth::user();
        if ($user === null) {
            $this->notifications = [];

            return;
        }

        $this->notifications = $user->notifications()
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (DatabaseNotification $notification): array => $this->normalizeNotification($notification))
            ->all();
    }

    public function markAsRead(string $notificationId): void
    {
        $notification = $this->findOwnedNotification($notificationId);
        if ($notification === null) {
            return;
        }

        $notification->markAsRead();
        $this->refreshBadge();
        $this->refreshList();
    }

    public function markAsUnread(string $notificationId): void
    {
        $notification = $this->findOwnedNotification($notificationId);
        if ($notification === null) {
            return;
        }

        $notification->update(['read_at' => null]);
        $this->refreshBadge();
        $this->refreshList();
    }

    public function openNotification(string $notificationId): void
    {
        $notification = $this->findOwnedNotification($notificationId);
        if ($notification === null) {
            return;
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        $this->refreshBadge();
        $this->refreshList();

        $this->redirect($this->resolveNotificationUrl($notification), navigate: true);
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.notifications.bell-dropdown');
    }

    private function findOwnedNotification(string $notificationId): ?DatabaseNotification
    {
        $user = Auth::user();
        if ($user === null) {
            return null;
        }

        /** @var DatabaseNotification|null $notification */
        $notification = $user->notifications()->whereKey($notificationId)->first();

        return $notification;
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
    private function normalizeNotification(DatabaseNotification $notification): array
    {
        $data = is_array($notification->data) ? $notification->data : [];
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

    private function resolveNotificationUrl(DatabaseNotification $notification): string
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $route = trim((string) ($data['route'] ?? ''));
        $params = is_array($data['params'] ?? null) ? $data['params'] : [];

        if ($route !== '' && Route::has($route)) {
            return route($route, $params);
        }

        return route('dashboard');
    }
}
