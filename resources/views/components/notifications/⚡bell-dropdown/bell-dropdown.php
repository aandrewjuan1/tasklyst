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

    public bool $panelOpen = false;

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
    }

    public function togglePanel(): void
    {
        $this->panelOpen = ! $this->panelOpen;

        if ($this->panelOpen) {
            $this->syncNotificationStateFromDatabase();
        }
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

    public function markAsRead(string $notificationId): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        app(MarkNotificationReadForUserAction::class)->execute($user, $notificationId);
        $this->syncNotificationStateFromDatabase();
    }

    public function markAsUnread(string $notificationId): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        app(MarkNotificationUnreadForUserAction::class)->execute($user, $notificationId);
        $this->syncNotificationStateFromDatabase();
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
