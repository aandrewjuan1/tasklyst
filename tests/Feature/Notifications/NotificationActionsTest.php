<?php

use App\Actions\Notification\MarkAllUnreadNotificationsReadForUserAction;
use App\Actions\Notification\MarkNotificationReadForUserAction;
use App\Actions\Notification\MarkVisibleNotificationsReadForUserAction;
use App\Actions\Notification\PrepareNotificationOpenRedirectForUserAction;
use App\Events\UserNotificationCreated;
use App\Http\Middleware\ValidateWorkOSSession;
use App\Models\DatabaseNotification;
use App\Models\User;
use App\Support\WorkspaceAgendaFocusUrl;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->withoutMiddleware(ValidateWorkOSSession::class);
});

test('mark read action marks owned notification', function (): void {
    $user = User::factory()->create();

    $notification = DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => [
            'title' => 'T',
            'message' => '',
            'route' => 'dashboard',
            'params' => [],
        ],
        'read_at' => null,
    ]);

    $ok = app(MarkNotificationReadForUserAction::class)->execute($user, $notification->id);

    expect($ok)->toBeTrue()
        ->and($notification->fresh()->read_at)->not->toBeNull();
});

test('mark read action returns false for another users notification', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $notification = DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $otherUser->id,
        'data' => [
            'title' => 'Other',
            'message' => '',
            'route' => 'dashboard',
            'params' => [],
        ],
        'read_at' => null,
    ]);

    $ok = app(MarkNotificationReadForUserAction::class)->execute($user, $notification->id);

    expect($ok)->toBeFalse()
        ->and($notification->fresh()->read_at)->toBeNull();
});

test('mark all unread notifications read updates every unread row for the user', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 3) as $index) {
        DatabaseNotification::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'App\\Notifications\\TestNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => [
                'title' => 'U '.$index,
                'message' => '',
                'route' => 'dashboard',
                'params' => [],
            ],
            'read_at' => null,
        ]);
    }

    $count = app(MarkAllUnreadNotificationsReadForUserAction::class)->execute($user);

    expect($count)->toBe(3)
        ->and($user->fresh()->unreadNotifications()->count())->toBe(0);
});

test('mark all unread notifications read broadcasts inbox update when rows were changed', function (): void {
    Event::fake([UserNotificationCreated::class]);
    $user = User::factory()->create();

    DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => [
            'title' => 'Unread',
            'message' => '',
            'route' => 'dashboard',
            'params' => [],
        ],
        'read_at' => null,
    ]);

    app(MarkAllUnreadNotificationsReadForUserAction::class)->execute($user);

    Event::assertDispatched(UserNotificationCreated::class);
});

test('mark all unread notifications read returns zero when nothing is unread', function (): void {
    $user = User::factory()->create();

    $count = app(MarkAllUnreadNotificationsReadForUserAction::class)->execute($user);

    expect($count)->toBe(0);
});

test('mark all unread notifications read does not affect another users notifications', function (): void {
    $user = User::factory()->create();
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
            'title' => 'Theirs',
            'message' => '',
            'route' => 'dashboard',
            'params' => [],
        ],
        'read_at' => null,
    ]);

    $count = app(MarkAllUnreadNotificationsReadForUserAction::class)->execute($user);

    expect($count)->toBe(1)
        ->and($otherNotification->fresh()->read_at)->toBeNull();
});

test('mark visible notifications read returns zero when id list is empty', function (): void {
    $user = User::factory()->create();

    DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => [
            'title' => 'Unread',
            'message' => '',
            'route' => 'dashboard',
            'params' => [],
        ],
        'read_at' => null,
    ]);

    $count = app(MarkVisibleNotificationsReadForUserAction::class)->execute($user, []);

    expect($count)->toBe(0)
        ->and($user->fresh()->unreadNotifications()->count())->toBe(1);
});

test('mark visible notifications read only updates unread rows among given ids', function (): void {
    $user = User::factory()->create();

    $readInWindow = DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => [
            'title' => 'Already read',
            'message' => '',
            'route' => 'dashboard',
            'params' => [],
        ],
        'read_at' => now(),
    ]);

    $unreadInWindow = DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => [
            'title' => 'Unread',
            'message' => '',
            'route' => 'dashboard',
            'params' => [],
        ],
        'read_at' => null,
    ]);

    $count = app(MarkVisibleNotificationsReadForUserAction::class)->execute($user, [
        $readInWindow->id,
        $unreadInWindow->id,
    ]);

    expect($count)->toBe(1)
        ->and($readInWindow->fresh()->read_at)->not->toBeNull()
        ->and($unreadInWindow->fresh()->read_at)->not->toBeNull();
});

test('mark visible notifications read updates only unread rows matching the id list', function (): void {
    $user = User::factory()->create();
    $base = now()->startOfMinute();

    $ids = [];
    foreach (range(1, 12) as $index) {
        $row = DatabaseNotification::query()->create([
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
        $ids[] = $row->id;
    }

    $newestTenIds = array_slice(array_reverse($ids), 0, 10);

    $count = app(MarkVisibleNotificationsReadForUserAction::class)->execute($user, $newestTenIds);

    expect($count)->toBe(10)
        ->and($user->fresh()->unreadNotifications()->count())->toBe(2);
});

test('prepare open redirect returns workspace url and marks unread notification read', function (): void {
    $user = User::factory()->create();
    $today = now()->toDateString();

    $notification = DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => [
            'type' => 'task_due_soon',
            'title' => 'Open',
            'message' => '',
            'route' => 'workspace',
            'params' => [
                'date' => $today,
                'type' => 'tasks',
            ],
        ],
        'read_at' => null,
    ]);

    $url = app(PrepareNotificationOpenRedirectForUserAction::class)->execute($user, $notification->id);

    expect($url)->toBe(route('workspace', ['date' => $today, 'type' => 'tasks']))
        ->and($notification->fresh()->read_at)->not->toBeNull();
});

test('prepare open redirect broadcasts inbox update when it marks an unread row read', function (): void {
    Event::fake([UserNotificationCreated::class]);
    $user = User::factory()->create();

    $notification = DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => [
            'type' => 'task_due_soon',
            'title' => 'Open',
            'message' => '',
            'route' => 'workspace',
            'params' => ['date' => now()->toDateString(), 'type' => 'tasks'],
        ],
        'read_at' => null,
    ]);

    app(PrepareNotificationOpenRedirectForUserAction::class)->execute($user, $notification->id);

    Event::assertDispatched(UserNotificationCreated::class);
});

test('prepare open redirect returns null and does not mark read for non task or event notification types', function (): void {
    $user = User::factory()->create();

    $notification = DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => [
            'type' => 'calendar_feed_sync_failed',
            'title' => 'Sync issue',
            'message' => '',
            'route' => 'workspace',
            'params' => [],
        ],
        'read_at' => null,
    ]);

    $url = app(PrepareNotificationOpenRedirectForUserAction::class)->execute($user, $notification->id);

    expect($url)->toBeNull()
        ->and($notification->fresh()->read_at)->toBeNull();
});

test('prepare open redirect opens workspace for newly actionable task-stalled notifications', function (): void {
    $user = User::factory()->create();
    $today = now()->toDateString();

    $notification = DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => [
            'type' => 'task_stalled',
            'title' => 'Stalled task',
            'message' => '',
            'route' => 'workspace',
            'params' => [
                'date' => $today,
                'type' => 'tasks',
                'task' => 77,
            ],
        ],
        'read_at' => null,
    ]);

    $url = app(PrepareNotificationOpenRedirectForUserAction::class)->execute($user, $notification->id);

    expect($url)->toBe(WorkspaceAgendaFocusUrl::workspaceRouteForAgendaStyleFocus($today, 'task', 77))
        ->and($notification->fresh()->read_at)->not->toBeNull();
});
