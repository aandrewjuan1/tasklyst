<?php

use App\Actions\Collaboration\AcceptCollaborationInvitationAction;
use App\Actions\Collaboration\DeclineCollaborationInvitationAction;
use App\Actions\Notification\FindOwnedDatabaseNotificationAction;
use App\Actions\Notification\MarkAllUnreadNotificationsReadForUserAction;
use App\Actions\Notification\PrepareNotificationOpenRedirectForUserAction;
use App\Enums\CollaborationInviteNotificationState;
use App\Models\CollaborationInvitation;
use App\Models\DatabaseNotification;
use App\Notifications\AssistantResponseReadyNotification;
use App\Notifications\CollaborationInvitationReceivedNotification;
use App\Notifications\CalendarFeedSyncCompletedNotification;
use App\Services\UserNotificationBroadcastService;
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
     *   notification_kind: string,
     *   title: string,
     *   message: string,
     *   route: string,
     *   params: array<string, mixed>,
     *   read_at: string|null,
     *   created_at_human: string,
     *   click_opens_workspace: bool,
     *   click_behavior?: 'assistant_response_ready'|'calendar_feed_sync_completed'|null,
     *   workspace_focus_kind?: 'task'|'event'|'project'|'schoolClass'|null,
     *   workspace_focus_id?: int|null,
     *   collaboration_invite?: array<string, mixed>
     * }>
     */
    public array $notifications = [];

    public bool $hasMoreNotifications = false;

    /**
     * Visual style for the trigger. Use "hero" on prominent surfaces (e.g. dashboard hero).
     */
    public string $variant = 'default';

    public function mount(): void
    {
        $this->userId = (int) Auth::id();
        $this->syncNotificationStateFromDatabase();
    }

    #[On('echo-private:App.Models.User.{userId},.notification_created')]
    public function onNotificationCreated(array $payload = []): void
    {
        $unreadCount = (int) ($payload['unread_count'] ?? $this->unreadCount);
        $this->unreadCount = max(0, $unreadCount);

        if ($this->panelOpen) {
            $this->syncNotificationStateFromDatabase();
        }
    }

    public function togglePanel(): void
    {
        $this->panelOpen = ! $this->panelOpen;

        if ($this->panelOpen) {
            $this->syncNotificationStateFromDatabase();
        }
    }

    public function closePanel(): void
    {
        $this->panelOpen = false;
    }

    private function syncNotificationStateFromDatabase(): void
    {
        $user = Auth::user();
        if ($user === null) {
            $this->unreadCount = 0;
            $this->notifications = [];
            $this->hasMoreNotifications = false;

            return;
        }

        $payload = NotificationBellState::payloadForUser($user);
        $this->unreadCount = $payload['unread_count'];
        $this->notifications = $payload['notifications'];
        $this->hasMoreNotifications = $payload['has_more'];
    }

    public function loadMoreNotifications(): void
    {
        $user = Auth::user();
        if ($user === null || ! $this->hasMoreNotifications) {
            return;
        }

        $offset = count($this->notifications);
        $page = NotificationBellState::notificationsPage($user, $offset, NotificationBellState::BELL_PAGE_SIZE);
        $this->notifications = array_merge($this->notifications, $page['notifications']);
        $this->hasMoreNotifications = $page['has_more'];
    }

    public function markAllAsRead(): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        $count = app(MarkAllUnreadNotificationsReadForUserAction::class)->execute($user);
        $this->syncNotificationStateFromDatabase();

        if ($count > 0) {
            $this->dispatch('toast', type: 'success', message: __('Notifications marked as read.'));
        }
    }

    public function openCalendarFeedSyncCompletedNotification(string $notificationId): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        $notification = app(FindOwnedDatabaseNotificationAction::class)->execute($user, $notificationId);
        if ($notification === null) {
            return;
        }

        if ($notification->type !== CalendarFeedSyncCompletedNotification::class) {
            return;
        }

        $data = NotificationBellState::notificationDataAsArray($notification);
        if (($data['type'] ?? '') !== 'calendar_feed_sync_completed') {
            return;
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
            app(UserNotificationBroadcastService::class)->broadcastInboxUpdated($user);
        }

        $this->syncNotificationStateFromDatabase();
        $this->panelOpen = false;

        if (request()->routeIs('workspace')) {
            $this->js('window.location.reload()');

            return;
        }

        $this->redirect(route('workspace'), navigate: true);
    }

    public function openAssistantResponseReadyNotification(string $notificationId): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        $notification = app(FindOwnedDatabaseNotificationAction::class)->execute($user, $notificationId);
        if ($notification === null) {
            return;
        }

        if ($notification->type !== AssistantResponseReadyNotification::class) {
            return;
        }

        $data = NotificationBellState::notificationDataAsArray($notification);
        if (($data['type'] ?? '') !== 'assistant_response_ready') {
            return;
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
            app(UserNotificationBroadcastService::class)->broadcastInboxUpdated($user);
        }

        $this->syncNotificationStateFromDatabase();
        $this->panelOpen = false;
        $this->dispatch('assistant-chat-open-requested');
        $this->js('$flux.modal("task-assistant-chat").show();');
    }

    public function openNotification(string $notificationId): void
    {
        $this->openNotificationInternal($notificationId, true);
    }

    /**
     * Called from the bell on the workspace page after optional {@see workspaceCalendarTryInstantFocus} (same pattern as the sidebar calendar agenda).
     *
     * @param  bool  $expandPagination  False when the list row was already in the DOM and instant scroll/highlight ran; skips heavy pagination expansion and deferred JS focus.
     */
    public function openNotificationFromWorkspaceBell(string $notificationId, bool $expandPagination): void
    {
        if (! request()->routeIs('workspace')) {
            $this->openNotification($notificationId);

            return;
        }

        $this->openNotificationInternal($notificationId, $expandPagination);
    }

    /**
     * Workspace page only: list row was already in the DOM and instant scroll/highlight ran in the browser.
     * Marks read and refreshes bell state without touching the workspace Livewire tree (avoids 1–2s focus/pagination work).
     */
    public function markWorkspaceNotificationOpened(string $notificationId): void
    {
        if (! request()->routeIs('workspace')) {
            return;
        }

        $user = Auth::user();
        if ($user === null) {
            return;
        }

        $notification = app(FindOwnedDatabaseNotificationAction::class)->execute($user, $notificationId);
        if ($notification === null) {
            return;
        }

        $data = NotificationBellState::notificationDataAsArray($notification);
        if (! NotificationBellState::notificationDataOpensWorkspaceRow($data)) {
            return;
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
            app(UserNotificationBroadcastService::class)->broadcastInboxUpdated($user);
        }

        $this->syncNotificationStateFromDatabase();
        $this->panelOpen = false;
    }

    private function openNotificationInternal(string $notificationId, bool $expandPaginationForWorkspace): void
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

        $this->panelOpen = false;

        if (request()->routeIs('workspace')) {
            $notification = app(FindOwnedDatabaseNotificationAction::class)->execute($user, $notificationId);
            $data = $notification !== null
                ? NotificationBellState::notificationDataAsArray($notification)
                : [];
            $target = NotificationBellState::workspaceFocusTargetFromNotificationData($data);

            if ($target !== null) {
                $this->dispatch(
                    'workspace-bell-focus-item',
                    kind: $target['kind'],
                    id: $target['id'],
                    expandPagination: $expandPaginationForWorkspace,
                );

                return;
            }
        }

        $this->redirect($url, navigate: true);
    }

    public function acceptCollaborationInvite(string $notificationId): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        $notification = app(FindOwnedDatabaseNotificationAction::class)->execute($user, $notificationId);
        if (! $this->assertCollaborationInviteActionable($notification)) {
            return;
        }

        $invitation = $this->resolveInvitationForCollaborationNotification($notification);
        if ($invitation === null) {
            $this->dispatch('toast', type: 'error', message: __('Invitation not found or already handled.'));
            $this->syncNotificationStateFromDatabase();

            return;
        }

        $this->authorize('accept', $invitation);

        app(AcceptCollaborationInvitationAction::class)->execute($invitation, $user);
        $invitation->refresh();

        if ($invitation->status !== 'accepted') {
            $this->dispatch('toast', type: 'error', message: __('Could not accept invitation. Please try again.'));
            $this->syncNotificationStateFromDatabase();

            return;
        }

        $this->markCollaborationInviteNotificationHandled($notification->fresh(), CollaborationInviteNotificationState::Accepted);
        $this->dispatch('collaboration-invitation-accepted');
        $this->dispatch('toast', type: 'success', message: __('Invitation accepted.'));
        $this->syncNotificationStateFromDatabase();
    }

    public function declineCollaborationInvite(string $notificationId): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        $notification = app(FindOwnedDatabaseNotificationAction::class)->execute($user, $notificationId);
        if (! $this->assertCollaborationInviteActionable($notification)) {
            return;
        }

        $invitation = $this->resolveInvitationForCollaborationNotification($notification);
        if ($invitation === null) {
            $this->dispatch('toast', type: 'error', message: __('Invitation not found or already handled.'));
            $this->syncNotificationStateFromDatabase();

            return;
        }

        $this->authorize('decline', $invitation);

        $ok = app(DeclineCollaborationInvitationAction::class)->execute($invitation, $user);
        if (! $ok) {
            $this->dispatch('toast', type: 'error', message: __('Could not decline invitation. Please try again.'));
            $this->syncNotificationStateFromDatabase();

            return;
        }

        $this->markCollaborationInviteNotificationHandled($notification->fresh(), CollaborationInviteNotificationState::Declined);
        $this->dispatch('collaboration-invitation-declined');
        $this->dispatch('toast', type: 'success', message: __('Invitation declined.'));
        $this->syncNotificationStateFromDatabase();
    }

    private function assertCollaborationInviteActionable(?DatabaseNotification $notification): bool
    {
        if ($notification === null) {
            $this->dispatch('toast', type: 'error', message: __('Invitation not found or already handled.'));
            $this->syncNotificationStateFromDatabase();

            return false;
        }

        if ($notification->type !== CollaborationInvitationReceivedNotification::class) {
            return false;
        }

        $state = $notification->collaboration_invite_state;
        if ($state === CollaborationInviteNotificationState::Accepted
            || $state === CollaborationInviteNotificationState::Declined) {
            $this->dispatch('toast', type: 'error', message: __('Invitation not found or already handled.'));
            $this->syncNotificationStateFromDatabase();

            return false;
        }

        return true;
    }

    private function resolveInvitationForCollaborationNotification(DatabaseNotification $notification): ?CollaborationInvitation
    {
        $data = NotificationBellState::notificationDataAsArray($notification);
        $entity = $data['entity'] ?? null;
        $invitationId = is_array($entity) && isset($entity['id']) ? (int) $entity['id'] : null;
        if ($invitationId === null || $invitationId === 0) {
            return null;
        }

        return CollaborationInvitation::query()
            ->whereKey($invitationId)
            ->with('collaboratable')
            ->first();
    }

    private function markCollaborationInviteNotificationHandled(
        ?DatabaseNotification $notification,
        CollaborationInviteNotificationState $state,
    ): void {
        if ($notification === null) {
            return;
        }

        $rawData = $notification->data;
        $data = is_array($rawData) ? $rawData : [];
        if ($data === [] && is_string($rawData) && $rawData !== '') {
            $decoded = json_decode($rawData, true);
            $data = is_array($decoded) ? $decoded : [];
        }

        if ($state === CollaborationInviteNotificationState::Accepted) {
            $data['title'] = __('Collaboration invite accepted');
            $data['message'] = __('You accepted this collaboration invitation.');
        } else {
            $data['title'] = __('Collaboration invite declined');
            $data['message'] = __('You declined this collaboration invitation.');
        }

        $notification->forceFill([
            'data' => $data,
            'collaboration_invite_state' => $state,
            'read_at' => now(),
        ])->save();

        $user = Auth::user();
        if ($user !== null) {
            app(UserNotificationBroadcastService::class)->broadcastInboxUpdated($user);
        }
    }
};
