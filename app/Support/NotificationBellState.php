<?php

namespace App\Support;

use App\Enums\CollaborationInviteNotificationState;
use App\Enums\CollaborationPermission;
use App\Models\CollaborationInvitation;
use App\Models\DatabaseNotification;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\CollaborationInvitationReceivedNotification;
use Illuminate\Support\Facades\Route;

final class NotificationBellState
{
    public const BELL_PAGE_SIZE = 5;

    /**
     * Workspace URLs omit the legacy search query parameter so they match dashboard/calendar deep links and stay stable after Livewire URL sync.
     *
     * When the notification resolves to a task/event/project row, the URL matches {@see WorkspaceAgendaFocusUrl::workspaceRouteForAgendaStyleFocus}
     * (agenda focus param, no "Show" type filter), same as the dashboard calendar agenda.
     */
    public static function resolveTargetUrl(DatabaseNotification $notification): string
    {
        $data = self::notificationDataAsArray($notification);
        $route = trim((string) ($data['route'] ?? ''));
        $params = is_array($data['params'] ?? null) ? $data['params'] : [];

        if ($route === 'workspace') {
            $params = self::mergeWorkspaceDeepLinkQueryParams($data, $params);
            unset($params['q']);

            $target = self::workspaceFocusTargetFromNotificationData($data);
            if ($target !== null) {
                $date = $params['date'] ?? null;
                $dateString = is_string($date) && $date !== ''
                    ? $date
                    : now()->toDateString();
                $urlKind = $target['kind'] === 'schoolClass' ? 'school_class' : $target['kind'];

                return WorkspaceAgendaFocusUrl::workspaceRouteForAgendaStyleFocus(
                    $dateString,
                    $urlKind,
                    $target['id'],
                );
            }
        }

        if ($route !== '' && Route::has($route)) {
            return route($route, $params);
        }

        return route('dashboard');
    }

    /**
     * Merge task/event/project id and list view into workspace route params using stored `entity` when ids are missing (legacy rows).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function mergeWorkspaceDeepLinkQueryParams(array $data, array $params): array
    {
        $out = $params;

        $hasTask = isset($out['task']) && (int) $out['task'] > 0;
        $hasEvent = isset($out['event']) && (int) $out['event'] > 0;
        $hasProject = isset($out['project']) && (int) $out['project'] > 0;
        $hasSchoolClass = isset($out['school_class']) && (int) $out['school_class'] > 0;

        if (! $hasTask && ! $hasEvent && ! $hasProject && ! $hasSchoolClass) {
            $entity = $data['entity'] ?? null;
            if (is_array($entity)) {
                $kind = isset($entity['kind']) ? strtolower((string) $entity['kind']) : '';
                $id = isset($entity['id']) ? (int) $entity['id'] : 0;

                if ($id > 0 && in_array($kind, ['task', 'event', 'project', 'schoolclass', 'school_class'], true)) {
                    unset($out['task'], $out['event'], $out['project'], $out['school_class']);
                    $out['view'] = 'list';

                    if ($kind === 'task') {
                        $out['task'] = $id;
                        $out['type'] = $out['type'] ?? 'tasks';
                    } elseif ($kind === 'event') {
                        $out['event'] = $id;
                        $out['type'] = $out['type'] ?? 'events';
                    } elseif ($kind === 'project') {
                        $out['project'] = $id;
                        $out['type'] = $out['type'] ?? 'projects';
                    } else {
                        $out['school_class'] = $id;
                        $out['type'] = $out['type'] ?? 'classes';
                    }
                }
            }
        }

        if (isset($out['task']) || isset($out['event']) || isset($out['project']) || isset($out['school_class'])) {
            $out['view'] = $out['view'] ?? 'list';
        }

        return $out;
    }

    /**
     * Resolved task/event/project target for in-page workspace focus (bell, etc.).
     *
     * @param  array<string, mixed>  $data
     * @return array{kind: 'task'|'event'|'project'|'schoolClass', id: int}|null
     */
    public static function workspaceFocusTargetFromNotificationData(array $data): ?array
    {
        if (! self::notificationDataOpensWorkspaceRow($data)) {
            return null;
        }

        $route = trim((string) ($data['route'] ?? ''));
        if ($route !== 'workspace') {
            return null;
        }

        $params = is_array($data['params'] ?? null) ? $data['params'] : [];
        $merged = self::mergeWorkspaceDeepLinkQueryParams($data, $params);

        if (isset($merged['task']) && (int) $merged['task'] > 0) {
            return ['kind' => 'task', 'id' => (int) $merged['task']];
        }
        if (isset($merged['event']) && (int) $merged['event'] > 0) {
            return ['kind' => 'event', 'id' => (int) $merged['event']];
        }
        if (isset($merged['project']) && (int) $merged['project'] > 0) {
            return ['kind' => 'project', 'id' => (int) $merged['project']];
        }
        if (isset($merged['school_class']) && (int) $merged['school_class'] > 0) {
            return ['kind' => 'schoolClass', 'id' => (int) $merged['school_class']];
        }

        return null;
    }

    /**
     * Only task- and event-related inbox rows use the primary click target to open the workspace.
     *
     * @param  array<string, mixed>  $data
     */
    public static function notificationDataOpensWorkspaceRow(array $data): bool
    {
        $type = (string) ($data['type'] ?? '');

        return in_array($type, [
            'task_due_soon',
            'task_overdue',
            'event_start_soon',
            'task_stalled',
            'project_deadline_risk',
            'recurrence_anomaly',
            'focus_session_completed',
            'collaborator_activity',
            'school_class_start_soon',
            'school_class_now_live',
            'school_class_ending_soon',
            'school_class_missed',
            'collaboration_invite_accepted_for_owner',
            'collaboration_invite_declined_for_owner',
            'assistant_schedule_accept_success',
        ], true);
    }

    /**
     * @return array{
     *   notifications: array<int, array<string, mixed>>,
     *   has_more: bool,
     *   unread_count: int,
     *   unread_label: string
     * }
     */
    public static function payloadForUser(User $user): array
    {
        $unreadCount = $user->unreadNotifications()->count();

        $page = self::notificationsPage($user, 0, self::BELL_PAGE_SIZE);

        $unreadLabel = $unreadCount > 0
            ? trans_choice(':count unread', $unreadCount, ['count' => $unreadCount])
            : '';

        return [
            'notifications' => $page['notifications'],
            'has_more' => $page['has_more'],
            'unread_count' => $unreadCount,
            'unread_label' => $unreadLabel,
        ];
    }

    /**
     * @return array{
     *   notifications: array<int, array<string, mixed>>,
     *   has_more: bool
     * }
     */
    public static function notificationsPage(User $user, int $offset, int $pageSize): array
    {
        $rows = $user->notifications()
            ->latest()
            ->skip($offset)
            ->take($pageSize + 1)
            ->get();

        $hasMore = $rows->count() > $pageSize;
        if ($hasMore) {
            $rows = $rows->take($pageSize);
        }

        $collaborationInvitationsById = self::collaborationInvitationsByIdForNotifications($rows->all());

        $notifications = $rows
            ->map(fn (DatabaseNotification $notification): array => self::normalizeNotification($notification, $collaborationInvitationsById))
            ->all();

        return [
            'notifications' => $notifications,
            'has_more' => $hasMore,
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
     *   notification_kind: string,
     *   title: string,
     *   message: string,
     *   route: string,
     *   params: array<string, mixed>,
     *   read_at: string|null,
     *   created_at_human: string,
     *   click_opens_workspace: bool,
     *   click_behavior: 'assistant_response_ready'|'calendar_feed_sync_completed'|null,
     *   workspace_focus_kind?: 'task'|'event'|'project'|'schoolClass'|null,
     *   workspace_focus_id?: int|null,
     *   collaboration_invite?: array<string, mixed>
     * }
     */
    private static function normalizeNotification(DatabaseNotification $notification, array $collaborationInvitationsById = []): array
    {
        $data = self::notificationDataAsArray($notification);
        $title = trim((string) ($data['title'] ?? ''));
        $message = trim((string) ($data['message'] ?? ''));
        $route = trim((string) ($data['route'] ?? ''));
        $params = is_array($data['params'] ?? null) ? $data['params'] : [];

        $type = (string) ($data['type'] ?? '');
        $clickBehavior = match ($type) {
            'assistant_response_ready' => 'assistant_response_ready',
            'calendar_feed_sync_completed' => 'calendar_feed_sync_completed',
            default => null,
        };

        $base = [
            'id' => (string) $notification->id,
            'notification_kind' => 'standard',
            'title' => $title !== '' ? $title : __('Notification'),
            'message' => $message,
            'route' => $route,
            'params' => $params,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at_human' => $notification->created_at?->diffForHumans() ?? __('Just now'),
            'click_opens_workspace' => self::notificationDataOpensWorkspaceRow($data),
            'click_behavior' => $clickBehavior,
        ];

        if (! self::isCollaborationInviteNotification($notification, $data)) {
            $focusTarget = null;
            if ($base['click_opens_workspace']) {
                $focusTarget = self::workspaceFocusTargetFromNotificationData($data);
            }

            return array_merge($base, [
                'workspace_focus_kind' => $focusTarget['kind'] ?? null,
                'workspace_focus_id' => $focusTarget['id'] ?? null,
            ]);
        }

        $invitationId = self::resolveInvitationIdFromNotificationData($data);
        if ($invitationId === null) {
            return array_merge($base, [
                'notification_kind' => 'collaboration_invite',
                'collaboration_invite' => [
                    'invitation_id' => 0,
                    'token' => null,
                    'item_title' => '',
                    'item_type' => 'item',
                    'inviter_name' => __('Someone'),
                    'permission_label' => __('Can view'),
                    'interaction' => 'unavailable',
                ],
                'title' => $base['title'],
                'message' => __('This invitation is no longer available.'),
            ]);
        }

        $invitation = $collaborationInvitationsById[$invitationId]
            ?? CollaborationInvitation::query()
                ->whereKey($invitationId)
                ->with(['collaboratable', 'inviter'])
                ->first();

        $columnState = $notification->collaboration_invite_state;

        $invitePayload = self::buildCollaborationInvitePayload($invitation, $columnState);

        $resolvedTitle = $base['title'];
        $resolvedMessage = $base['message'];
        if ($invitePayload['interaction'] === 'accepted') {
            $resolvedTitle = __('Collaboration invite accepted');
            $resolvedMessage = __('You accepted this collaboration invitation.');
        } elseif ($invitePayload['interaction'] === 'declined') {
            $resolvedTitle = __('Collaboration invite declined');
            $resolvedMessage = __('You declined this collaboration invitation.');
        } elseif ($invitePayload['interaction'] === 'expired') {
            $resolvedMessage = __('This invitation has expired.');
        } elseif ($invitePayload['interaction'] === 'unavailable') {
            $resolvedMessage = __('This invitation is no longer available.');
        }

        return array_merge($base, [
            'notification_kind' => 'collaboration_invite',
            'title' => $resolvedTitle,
            'message' => $resolvedMessage,
            'collaboration_invite' => $invitePayload,
        ]);
    }

    /**
     * @param  array<int, DatabaseNotification>  $notifications
     * @return array<int, CollaborationInvitation>
     */
    private static function collaborationInvitationsByIdForNotifications(array $notifications): array
    {
        $invitationIds = collect($notifications)
            ->filter(fn (mixed $notification): bool => $notification instanceof DatabaseNotification)
            ->map(function (DatabaseNotification $notification): ?int {
                $data = self::notificationDataAsArray($notification);
                if (! self::isCollaborationInviteNotification($notification, $data)) {
                    return null;
                }

                return self::resolveInvitationIdFromNotificationData($data);
            })
            ->filter(fn (mixed $invitationId): bool => is_int($invitationId) && $invitationId > 0)
            ->values()
            ->all();

        if ($invitationIds === []) {
            return [];
        }

        /** @var array<int, CollaborationInvitation> $invitationsById */
        $invitationsById = CollaborationInvitation::query()
            ->whereIn('id', $invitationIds)
            ->with(['collaboratable', 'inviter'])
            ->get()
            ->keyBy('id')
            ->all();

        return $invitationsById;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function isCollaborationInviteNotification(DatabaseNotification $notification, array $data): bool
    {
        return $notification->type === CollaborationInvitationReceivedNotification::class
            || ($data['type'] ?? '') === 'collaboration_invite_received';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function resolveInvitationIdFromNotificationData(array $data): ?int
    {
        $entity = $data['entity'] ?? null;
        if (is_array($entity) && isset($entity['id'])) {
            return (int) $entity['id'];
        }
        $meta = $data['meta'] ?? null;
        if (is_array($meta) && isset($meta['invitation_id'])) {
            return (int) $meta['invitation_id'];
        }

        return null;
    }

    /**
     * @return array{
     *   invitation_id: int,
     *   token: string|null,
     *   item_title: string,
     *   item_type: string,
     *   inviter_name: string,
     *   permission_label: string,
     *   interaction: string
     * }
     */
    private static function buildCollaborationInvitePayload(
        ?CollaborationInvitation $invitation,
        ?CollaborationInviteNotificationState $columnState,
    ): array {
        if ($invitation === null) {
            return [
                'invitation_id' => 0,
                'token' => null,
                'item_title' => '',
                'item_type' => 'item',
                'inviter_name' => __('Someone'),
                'permission_label' => __('Can view'),
                'interaction' => 'unavailable',
            ];
        }

        $collaboratable = $invitation->collaboratable;
        $itemTitle = $collaboratable !== null
            ? (string) ($collaboratable->title ?? $collaboratable->name ?? $collaboratable->id)
            : (string) $invitation->id;

        $itemType = match ($invitation->collaboratable_type) {
            Task::class => 'task',
            Event::class => 'event',
            Project::class => 'project',
            default => 'item',
        };

        $inviterName = $invitation->inviter?->name ?? $invitation->inviter?->email ?? __('Someone');

        $permissionEnum = $invitation->permission;
        $permissionLabel = match ($permissionEnum) {
            CollaborationPermission::Edit => __('Can edit'),
            CollaborationPermission::View, null => __('Can view'),
        };

        $isExpired = $invitation->expires_at !== null && $invitation->expires_at->isPast();
        $status = $invitation->status;

        $interaction = self::resolveCollaborationInviteInteraction(
            $columnState,
            $status,
            $isExpired,
        );

        $token = ($interaction === 'pending') ? $invitation->token : null;

        return [
            'invitation_id' => (int) $invitation->id,
            'token' => $token,
            'item_title' => $itemTitle,
            'item_type' => $itemType,
            'inviter_name' => $inviterName,
            'permission_label' => $permissionLabel,
            'interaction' => $interaction,
        ];
    }

    private static function resolveCollaborationInviteInteraction(
        ?CollaborationInviteNotificationState $columnState,
        string $status,
        bool $isExpired,
    ): string {
        if ($status === 'accepted') {
            return 'accepted';
        }
        if ($status === 'declined') {
            return 'declined';
        }

        if ($status !== 'pending') {
            return 'unavailable';
        }

        if ($isExpired) {
            return 'expired';
        }

        if ($columnState === CollaborationInviteNotificationState::Accepted) {
            return 'accepted';
        }
        if ($columnState === CollaborationInviteNotificationState::Declined) {
            return 'declined';
        }

        return 'pending';
    }
}
