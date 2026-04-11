<?php

use App\Http\Middleware\ValidateWorkOSSession;
use App\Models\User;
use App\Support\NotificationBellState;
use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutMiddleware(ValidateWorkOSSession::class);

    $this->user = User::factory()->create();
});

test('pullStateForClient matches NotificationBellState payload for user', function (): void {
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

        $fromLivewire = Livewire::actingAs($user)
            ->test('notifications.bell-dropdown')
            ->instance()
            ->pullStateForClient();

        $fromSupport = NotificationBellState::payloadForUser($user->fresh());

        expect($fromLivewire)->toEqual($fromSupport);
    } finally {
        Carbon::setTestNow();
    }
});

test('pullStateForClient returns Alpine payload shape', function (): void {
    $user = $this->user;

    $component = Livewire::actingAs($user)->test('notifications.bell-dropdown');

    $payload = $component->instance()->pullStateForClient();

    expect($payload)->toHaveKeys(['notifications', 'unread_count', 'unread_label'])
        ->and($payload['notifications'])->toBeArray()
        ->and($payload['unread_count'])->toBeInt()
        ->and($payload['unread_label'])->toBeString();
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

test('mark read and unread actions update notification read state', function (): void {
    $user = $this->user;

    $notification = DatabaseNotification::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => [
            'title' => 'Unread notification',
            'message' => 'Message',
            'route' => 'dashboard',
            'params' => [],
        ],
        'read_at' => null,
    ]);

    $component = Livewire::actingAs($user)->test('notifications.bell-dropdown');

    $component->call('markAsRead', $notification->id);
    expect($notification->fresh()->read_at)->not->toBeNull()
        ->and($component->get('unreadCount'))->toBe(0);

    $component->call('markAsUnread', $notification->id);
    expect($notification->fresh()->read_at)->toBeNull()
        ->and($component->get('unreadCount'))->toBe(1);
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

test('user cannot mutate another users notification', function (): void {
    $user = $this->user;
    $otherUser = User::factory()->create();

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
        ->call('markAsRead', $otherNotification->id);

    expect($otherNotification->fresh()->read_at)->toBeNull();
});
