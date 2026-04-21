<?php

use App\Enums\CollaborationInviteNotificationState;
use App\Http\Middleware\ValidateWorkOSSession;
use App\Models\CollaborationInvitation;
use App\Models\DatabaseNotification;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AssistantResponseReadyNotification;
use App\Notifications\CollaborationInvitationReceivedNotification;
use App\Support\NotificationBellState;
use App\Support\WorkspaceAgendaFocusUrl;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutMiddleware(ValidateWorkOSSession::class);

    $this->user = User::factory()->create();
});

test('mounted bell state matches NotificationBellState payload for user', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-11 12:00:00'));

    try {
        $user = $this->user;

        DatabaseNotification::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'App\\Notifications\\TestNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => [
                'title' => 'Sync title',
                'message' => 'Sync message',
                'route' => 'dashboard',
                'params' => [],
            ],
            'read_at' => null,
        ]);

        $component = Livewire::actingAs($user)->test('notifications.bell-dropdown');
        $payload = NotificationBellState::payloadForUser($user->fresh());

        expect($component->get('unreadCount'))->toBe($payload['unread_count'])
            ->and($component->get('notifications'))->toEqual($payload['notifications'])
            ->and($component->get('hasMoreNotifications'))->toBe($payload['has_more']);
    } finally {
        Carbon::setTestNow();
    }
});

test('bell exposes notifications and unreadCount with expected types', function (): void {
    $user = $this->user;

    $component = Livewire::actingAs($user)->test('notifications.bell-dropdown');

    expect($component->get('notifications'))->toBeArray()
        ->and($component->get('unreadCount'))->toBeInt()
        ->and($component->get('panelOpen'))->toBeBool()
        ->and($component->get('hasMoreNotifications'))->toBeBool();
});

test('notification created event payload updates unread count without full list refresh while panel is closed', function (): void {
    $user = $this->user;

    DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => [
            'title' => 'Initial',
            'message' => 'Initial message',
            'route' => 'dashboard',
            'params' => [],
        ],
        'read_at' => null,
    ]);

    $component = Livewire::actingAs($user)->test('notifications.bell-dropdown');

    expect($component->get('unreadCount'))->toBe(1)
        ->and($component->get('notifications'))->toHaveCount(1)
        ->and($component->get('panelOpen'))->toBeFalse();

    DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => [
            'title' => 'New unseen',
            'message' => 'Should appear after open',
            'route' => 'dashboard',
            'params' => [],
        ],
        'read_at' => null,
    ]);

    $component->call('onNotificationCreated', ['unread_count' => 2]);

    expect($component->get('unreadCount'))->toBe(2)
        ->and($component->get('notifications'))->toHaveCount(1);

    $component->call('togglePanel');

    expect($component->get('notifications'))->toHaveCount(2);
});

test('close panel action closes the notification popover', function (): void {
    $user = $this->user;

    $component = Livewire::actingAs($user)->test('notifications.bell-dropdown');

    $component->call('togglePanel');

    expect($component->get('panelOpen'))->toBeTrue();

    $component->call('closePanel');

    expect($component->get('panelOpen'))->toBeFalse();
});

test('bell dropdown renders notification title and message in the dom', function (): void {
    $user = $this->user;

    DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => [
            'title' => 'Bell title seed unique',
            'message' => 'Bell message body unique',
            'route' => 'dashboard',
            'params' => [],
        ],
        'read_at' => null,
    ]);

    Livewire::actingAs($user)
        ->test('notifications.bell-dropdown')
        ->call('togglePanel')
        ->assertSee('data-test="notifications-close-panel"', false)
        ->assertSee('Bell title seed unique')
        ->assertSee('Bell message body unique');
});

test('bell dropdown shows full unread count and first page of five notifications with load more', function (): void {
    $user = $this->user;

    foreach (range(1, 12) as $index) {
        DatabaseNotification::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'App\\Notifications\\TestNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => [
                'title' => 'Notification '.$index,
                'message' => 'Message '.$index,
                'route' => 'dashboard',
                'params' => [],
            ],
            'read_at' => $index <= 2 ? now() : null,
        ]);
    }

    $component = Livewire::actingAs($user)->test('notifications.bell-dropdown');

    expect($component->get('unreadCount'))->toBe(10)
        ->and($component->get('notifications'))->toHaveCount(5)
        ->and($component->get('hasMoreNotifications'))->toBeTrue();

    $component->call('loadMoreNotifications');

    expect($component->get('notifications'))->toHaveCount(10)
        ->and($component->get('hasMoreNotifications'))->toBeTrue();

    $component->call('loadMoreNotifications');

    expect($component->get('notifications'))->toHaveCount(12)
        ->and($component->get('hasMoreNotifications'))->toBeFalse();
});

test('mark all as read marks unread items and refreshes bell state', function (): void {
    $user = $this->user;

    foreach (range(1, 3) as $index) {
        DatabaseNotification::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'App\\Notifications\\TestNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => [
                'title' => 'Unread '.$index,
                'message' => 'Message',
                'route' => 'dashboard',
                'params' => [],
            ],
            'read_at' => null,
        ]);
    }

    $component = Livewire::actingAs($user)->test('notifications.bell-dropdown');

    expect($component->get('unreadCount'))->toBe(3);

    $component->call('markAllAsRead');

    expect($user->fresh()->unreadNotifications()->count())->toBe(0)
        ->and($component->get('unreadCount'))->toBe(0);
});

test('mark all as read marks every unread notification including rows not yet loaded in the bell panel', function (): void {
    $user = $this->user;
    $base = now()->startOfMinute();

    foreach (range(1, 12) as $index) {
        DatabaseNotification::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'App\\Notifications\\TestNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => [
                'title' => 'N '.$index,
                'message' => '',
                'route' => 'dashboard',
                'params' => [],
            ],
            'read_at' => null,
            'created_at' => $base->copy()->addMinutes($index),
            'updated_at' => $base->copy()->addMinutes($index),
        ]);
    }

    $component = Livewire::actingAs($user)->test('notifications.bell-dropdown');

    expect($component->get('unreadCount'))->toBe(12);

    $component->call('markAllAsRead');

    expect($user->fresh()->unreadNotifications()->count())->toBe(0)
        ->and($component->get('unreadCount'))->toBe(0);
});

test('mark all as read marks every unread notification after load more expanded the list', function (): void {
    $user = $this->user;
    $base = now()->startOfMinute();

    foreach (range(1, 12) as $index) {
        DatabaseNotification::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'App\\Notifications\\TestNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => [
                'title' => 'N '.$index,
                'message' => '',
                'route' => 'dashboard',
                'params' => [],
            ],
            'read_at' => null,
            'created_at' => $base->copy()->addMinutes($index),
            'updated_at' => $base->copy()->addMinutes($index),
        ]);
    }

    $component = Livewire::actingAs($user)->test('notifications.bell-dropdown');

    $component->call('loadMoreNotifications');

    expect($component->get('notifications'))->toHaveCount(10);

    $component->call('markAllAsRead');

    expect($user->fresh()->unreadNotifications()->count())->toBe(0)
        ->and($component->get('unreadCount'))->toBe(0);
});

test('open notification marks it as read and redirects to target route', function (): void {
    $user = $this->user;
    $today = now()->toDateString();

    $notification = DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => [
            'type' => 'task_due_soon',
            'title' => 'Open me',
            'message' => 'Go to workspace',
            'route' => 'workspace',
            'params' => [
                'date' => $today,
                'type' => 'tasks',
            ],
        ],
        'read_at' => null,
    ]);

    Livewire::actingAs($user)
        ->test('notifications.bell-dropdown')
        ->call('openNotification', $notification->id)
        ->assertRedirect(route('workspace', ['date' => $today, 'type' => 'tasks']));

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('resolveTargetUrl merges task id from entity when params omit task', function (): void {
    $user = $this->user;
    $today = now()->toDateString();
    $taskId = 4242;

    $notification = DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => [
            'type' => 'task_due_soon',
            'title' => 'Legacy row',
            'route' => 'workspace',
            'params' => [
                'date' => $today,
                'type' => 'tasks',
            ],
            'entity' => [
                'kind' => 'task',
                'id' => $taskId,
            ],
        ],
        'read_at' => null,
    ]);

    $url = NotificationBellState::resolveTargetUrl($notification);

    expect($url)->toBe(WorkspaceAgendaFocusUrl::workspaceRouteForAgendaStyleFocus($today, 'task', $taskId));
});

test('workspaceFocusTargetFromNotificationData returns kind and id for merged workspace params', function (): void {
    $data = [
        'type' => 'task_due_soon',
        'route' => 'workspace',
        'params' => [
            'date' => now()->toDateString(),
            'view' => 'list',
            'type' => 'tasks',
            'task' => 99,
        ],
    ];

    $target = NotificationBellState::workspaceFocusTargetFromNotificationData($data);

    expect($target)->toMatchArray(['kind' => 'task', 'id' => 99]);
});

test('assistant schedule accept success notification opens workspace row from bell', function (): void {
    $data = [
        'type' => 'assistant_schedule_accept_success',
        'route' => 'workspace',
        'params' => [
            'date' => now()->toDateString(),
            'view' => 'list',
            'type' => 'tasks',
            'task' => 123,
        ],
    ];

    expect(NotificationBellState::notificationDataOpensWorkspaceRow($data))->toBeTrue();

    $target = NotificationBellState::workspaceFocusTargetFromNotificationData($data);
    expect($target)->toMatchArray(['kind' => 'task', 'id' => 123]);
});

test('assistant response ready notification exposes assistant click behavior', function (): void {
    $user = $this->user;

    $notification = DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => AssistantResponseReadyNotification::class,
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => [
            'type' => 'assistant_response_ready',
            'title' => 'Assistant response ready',
            'message' => 'Your task assistant response is ready to review.',
            'route' => 'dashboard',
            'params' => [],
            'meta' => [
                'thread_id' => 10,
                'assistant_message_id' => 20,
            ],
        ],
        'read_at' => null,
    ]);

    $component = Livewire::actingAs($user)->test('notifications.bell-dropdown');
    $first = $component->get('notifications')[0] ?? null;

    expect($first)->toBeArray()
        ->and($first['id'] ?? null)->toBe((string) $notification->id)
        ->and($first['click_behavior'] ?? null)->toBe('assistant_response_ready');
});

test('open assistant response ready notification marks read and dispatches flyout open event', function (): void {
    $user = $this->user;

    $notification = DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => AssistantResponseReadyNotification::class,
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => [
            'type' => 'assistant_response_ready',
            'title' => 'Assistant response ready',
            'message' => 'Your task assistant response is ready to review.',
            'route' => 'dashboard',
            'params' => [],
            'meta' => [
                'thread_id' => 10,
                'assistant_message_id' => 20,
            ],
        ],
        'read_at' => null,
    ]);

    Livewire::actingAs($user)
        ->test('notifications.bell-dropdown')
        ->call('openAssistantResponseReadyNotification', (string) $notification->id)
        ->assertDispatched('assistant-chat-open-requested');

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('school class notifications open workspace row from bell', function (): void {
    $data = [
        'type' => 'school_class_start_soon',
        'route' => 'workspace',
        'params' => [
            'date' => now()->toDateString(),
            'view' => 'list',
            'type' => 'classes',
            'school_class' => 456,
        ],
    ];

    expect(NotificationBellState::notificationDataOpensWorkspaceRow($data))->toBeTrue();

    $target = NotificationBellState::workspaceFocusTargetFromNotificationData($data);
    expect($target)->toMatchArray(['kind' => 'schoolClass', 'id' => 456]);
});

test('markWorkspaceNotificationOpened does not mark read when not on workspace route', function (): void {
    $user = $this->user;
    $today = now()->toDateString();

    $notification = DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => [
            'type' => 'task_due_soon',
            'title' => 'Unread',
            'route' => 'workspace',
            'params' => [
                'date' => $today,
                'type' => 'tasks',
                'task' => 1,
            ],
        ],
        'read_at' => null,
    ]);

    Livewire::actingAs($user)
        ->test('notifications.bell-dropdown')
        ->call('markWorkspaceNotificationOpened', $notification->id);

    expect($notification->fresh()->read_at)->toBeNull();
});

test('mark all as read does not affect another users notifications', function (): void {
    $user = $this->user;
    $otherUser = User::factory()->create();

    DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => [
            'title' => 'Mine',
            'message' => '',
            'route' => 'dashboard',
            'params' => [],
        ],
        'read_at' => null,
    ]);

    $otherNotification = DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $otherUser->id,
        'data' => [
            'title' => 'Other user',
            'message' => 'Should not mutate',
            'route' => 'dashboard',
            'params' => [],
        ],
        'read_at' => null,
    ]);

    Livewire::actingAs($user)
        ->test('notifications.bell-dropdown')
        ->call('markAllAsRead');

    expect($otherNotification->fresh()->read_at)->toBeNull();
});

test('collaboration invite notification row shows accept and decline in the bell panel', function (): void {
    $owner = User::factory()->create(['name' => 'Inviter Bell Person']);
    $invitee = User::factory()->create();
    $task = Task::factory()->create([
        'user_id' => $owner->id,
        'title' => 'Unique collab bell task title',
    ]);
    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $owner->id,
        'invitee_email' => $invitee->email,
        'invitee_user_id' => $invitee->id,
        'status' => 'pending',
    ]);

    DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => CollaborationInvitationReceivedNotification::class,
        'notifiable_type' => User::class,
        'notifiable_id' => $invitee->id,
        'data' => [
            'type' => 'collaboration_invite_received',
            'title' => __('Collaboration invite'),
            'message' => __('You received a collaboration invitation.'),
            'entity' => [
                'kind' => 'collaboration_invitation',
                'id' => $invitation->id,
                'model' => CollaborationInvitation::class,
            ],
            'route' => 'workspace',
            'params' => ['date' => now()->toDateString()],
            'meta' => [
                'invitee_email' => $invitee->email,
                'collaboratable_type' => Task::class,
                'collaboratable_id' => $task->id,
                'permission' => 'view',
            ],
        ],
        'read_at' => null,
    ]);

    Livewire::actingAs($invitee)
        ->test('notifications.bell-dropdown')
        ->call('togglePanel')
        ->assertSee(__('Accept'))
        ->assertSee(__('Decline'))
        ->assertSee('Unique collab bell task title');
});

test('bell accept collaboration invite updates invitation and notification state', function (): void {
    $owner = User::factory()->create();
    $invitee = User::factory()->create();
    $task = Task::factory()->create(['user_id' => $owner->id, 'title' => 'Accept me']);
    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $owner->id,
        'invitee_email' => $invitee->email,
        'invitee_user_id' => $invitee->id,
        'status' => 'pending',
    ]);

    $notification = DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => CollaborationInvitationReceivedNotification::class,
        'notifiable_type' => User::class,
        'notifiable_id' => $invitee->id,
        'data' => [
            'type' => 'collaboration_invite_received',
            'title' => __('Collaboration invite'),
            'message' => __('You received a collaboration invitation.'),
            'entity' => [
                'kind' => 'collaboration_invitation',
                'id' => $invitation->id,
                'model' => CollaborationInvitation::class,
            ],
            'route' => 'workspace',
            'params' => [],
            'meta' => [
                'invitee_email' => $invitee->email,
                'collaboratable_type' => Task::class,
                'collaboratable_id' => $task->id,
                'permission' => 'view',
            ],
        ],
        'read_at' => null,
    ]);

    Livewire::actingAs($invitee)
        ->test('notifications.bell-dropdown')
        ->call('acceptCollaborationInvite', $notification->id);

    $payload = $notification->fresh()->data;

    expect($invitation->fresh()->status)->toBe('accepted')
        ->and($notification->fresh()->collaboration_invite_state)->toBe(CollaborationInviteNotificationState::Accepted)
        ->and($notification->fresh()->read_at)->not->toBeNull()
        ->and(data_get($payload, 'type'))->toBe('collaboration_invite_received')
        ->and((int) data_get($payload, 'entity.id'))->toBe($invitation->id)
        ->and((string) data_get($payload, 'route'))->toBe('workspace')
        ->and((string) data_get($payload, 'meta.invitee_email'))->toBe($invitee->email);
});

test('bell decline collaboration invite updates invitation and notification state', function (): void {
    $owner = User::factory()->create();
    $invitee = User::factory()->create();
    $task = Task::factory()->create(['user_id' => $owner->id]);
    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $owner->id,
        'invitee_email' => $invitee->email,
        'invitee_user_id' => $invitee->id,
        'status' => 'pending',
    ]);

    $notification = DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => CollaborationInvitationReceivedNotification::class,
        'notifiable_type' => User::class,
        'notifiable_id' => $invitee->id,
        'data' => [
            'type' => 'collaboration_invite_received',
            'title' => __('Collaboration invite'),
            'message' => __('You received a collaboration invitation.'),
            'entity' => [
                'kind' => 'collaboration_invitation',
                'id' => $invitation->id,
                'model' => CollaborationInvitation::class,
            ],
            'route' => 'workspace',
            'params' => ['date' => now()->toDateString()],
            'meta' => [
                'invitee_email' => $invitee->email,
                'collaboratable_type' => Task::class,
                'collaboratable_id' => $task->id,
                'permission' => 'view',
            ],
        ],
        'read_at' => null,
    ]);

    Livewire::actingAs($invitee)
        ->test('notifications.bell-dropdown')
        ->call('declineCollaborationInvite', $notification->id);

    $payload = $notification->fresh()->data;

    expect($invitation->fresh()->status)->toBe('declined')
        ->and($notification->fresh()->collaboration_invite_state)->toBe(CollaborationInviteNotificationState::Declined)
        ->and($notification->fresh()->read_at)->not->toBeNull()
        ->and(data_get($payload, 'type'))->toBe('collaboration_invite_received')
        ->and((int) data_get($payload, 'entity.id'))->toBe($invitation->id)
        ->and((string) data_get($payload, 'route'))->toBe('workspace')
        ->and((string) data_get($payload, 'meta.invitee_email'))->toBe($invitee->email);
});

test('bell accept collaboration invite does nothing for another users notification', function (): void {
    $owner = User::factory()->create();
    $invitee = User::factory()->create();
    $stranger = User::factory()->create();
    $task = Task::factory()->create(['user_id' => $owner->id]);
    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $owner->id,
        'invitee_email' => $invitee->email,
        'invitee_user_id' => $invitee->id,
        'status' => 'pending',
    ]);

    $notification = DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => CollaborationInvitationReceivedNotification::class,
        'notifiable_type' => User::class,
        'notifiable_id' => $invitee->id,
        'data' => [
            'type' => 'collaboration_invite_received',
            'title' => __('Collaboration invite'),
            'message' => __('You received a collaboration invitation.'),
            'entity' => [
                'kind' => 'collaboration_invitation',
                'id' => $invitation->id,
                'model' => CollaborationInvitation::class,
            ],
            'route' => 'workspace',
            'params' => [],
            'meta' => [
                'invitee_email' => $invitee->email,
                'collaboratable_type' => Task::class,
                'collaboratable_id' => $task->id,
                'permission' => 'view',
            ],
        ],
        'read_at' => null,
    ]);

    Livewire::actingAs($stranger)
        ->test('notifications.bell-dropdown')
        ->call('acceptCollaborationInvite', $notification->id);

    expect($invitation->fresh()->status)->toBe('pending')
        ->and($notification->fresh()->collaboration_invite_state)->toBe(CollaborationInviteNotificationState::Pending);
});
