<?php

use App\Enums\CollaborationInviteNotificationState;
use App\Http\Middleware\ValidateWorkOSSession;
use App\Models\CollaborationInvitation;
use App\Models\DatabaseNotification;
use App\Models\Task;
use App\Models\User;
use App\Notifications\CollaborationInvitationReceivedNotification;
use App\Support\NotificationBellState;
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
            ->and($component->get('notifications'))->toEqual($payload['notifications']);
    } finally {
        Carbon::setTestNow();
    }
});

test('bell exposes notifications and unreadCount with expected types', function (): void {
    $user = $this->user;

    $component = Livewire::actingAs($user)->test('notifications.bell-dropdown');

    expect($component->get('notifications'))->toBeArray()
        ->and($component->get('unreadCount'))->toBeInt()
        ->and($component->get('panelOpen'))->toBeBool();
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
        ->assertSee('Bell title seed unique')
        ->assertSee('Bell message body unique');
});

test('bell dropdown shows unread count and latest 10 notifications only', function (): void {
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
        ->and($component->get('notifications'))->toHaveCount(10);
});

test('mark all visible as read marks unread items in latest ten and refreshes bell state', function (): void {
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

    $component->call('markAllVisibleAsRead');

    expect($user->fresh()->unreadNotifications()->count())->toBe(0)
        ->and($component->get('unreadCount'))->toBe(0);
});

test('mark all visible as read leaves older unread notifications when more than ten exist', function (): void {
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

    $component->call('markAllVisibleAsRead');

    expect($user->fresh()->unreadNotifications()->count())->toBe(2)
        ->and($component->get('unreadCount'))->toBe(2);
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

test('mark all visible as read does not affect another users notifications', function (): void {
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
        ->call('markAllVisibleAsRead');

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

    expect($invitation->fresh()->status)->toBe('accepted')
        ->and($notification->fresh()->collaboration_invite_state)->toBe(CollaborationInviteNotificationState::Accepted)
        ->and($notification->fresh()->read_at)->not->toBeNull();
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
        ->call('declineCollaborationInvite', $notification->id);

    expect($invitation->fresh()->status)->toBe('declined')
        ->and($notification->fresh()->collaboration_invite_state)->toBe(CollaborationInviteNotificationState::Declined)
        ->and($notification->fresh()->read_at)->not->toBeNull();
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
