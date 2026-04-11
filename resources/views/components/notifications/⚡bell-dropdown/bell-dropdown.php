<?php

use App\Actions\Notification\MarkNotificationReadForUserAction;
use App\Actions\Notification\MarkNotificationUnreadForUserAction;
use App\Actions\Notification\PrepareNotificationOpenRedirectForUserAction;
use App\Support\NotificationBellState;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
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
        $this->syncNotificationStateFromDatabase();
    }

    #[On('echo-private:App.Models.User.{userId},.notification_created')]
    public function onNotificationCreated(): void
    {
        $this->syncNotificationStateFromDatabase();
        $this->dispatch('notification-bell-sync');
    }

    /**
     * @return array{
     *   notifications: array<int, array<string, mixed>>,
     *   unread_count: int,
     *   unread_label: string
     * }
     */
    public function pullStateForClient(): array
    {
        $this->syncNotificationStateFromDatabase();

        return $this->clientStatePayload();
    }

    /**
     * @return array{
     *   notifications: array<int, array<string, mixed>>,
     *   unread_count: int,
     *   unread_label: string
     * }
     */
    private function clientStatePayload(): array
    {
        $unreadLabel = $this->unreadCount > 0
            ? trans_choice(':count unread', $this->unreadCount, ['count' => $this->unreadCount])
            : '';

        return [
            'notifications' => $this->notifications,
            'unread_count' => $this->unreadCount,
            'unread_label' => $unreadLabel,
        ];
    }

    private function syncNotificationStateFromDatabase(): void
    {
        $user = Auth::user();
        if ($user === null) {
            $this->unreadCount = 0;
            $this->notifications = [];

            return;
        }

        $payload = NotificationBellState::payloadForUser($user);
        $this->unreadCount = $payload['unread_count'];
        $this->notifications = $payload['notifications'];
    }

    /**
     * @return array{
     *   notifications: array<int, array<string, mixed>>,
     *   unread_count: int,
     *   unread_label: string
     * }
     */
    public function markAsRead(string $notificationId): array
    {
        $user = Auth::user();
        if ($user === null) {
            return $this->clientStatePayload();
        }

        app(MarkNotificationReadForUserAction::class)->execute($user, $notificationId);
        $this->syncNotificationStateFromDatabase();

        return $this->clientStatePayload();
    }

    /**
     * @return array{
     *   notifications: array<int, array<string, mixed>>,
     *   unread_count: int,
     *   unread_label: string
     * }
     */
    public function markAsUnread(string $notificationId): array
    {
        $user = Auth::user();
        if ($user === null) {
            return $this->clientStatePayload();
        }

        app(MarkNotificationUnreadForUserAction::class)->execute($user, $notificationId);
        $this->syncNotificationStateFromDatabase();

        return $this->clientStatePayload();
    }

    public function openNotification(string $notificationId): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        $url = app(PrepareNotificationOpenRedirectForUserAction::class)->execute($user, $notificationId);
        if ($url === null) {
            return;
        }

        $this->syncNotificationStateFromDatabase();

        $this->redirect($url, navigate: true);
    }
};
