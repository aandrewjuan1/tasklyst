<?php

use App\Actions\Notification\MarkNotificationReadForUserAction;
use App\Actions\Notification\MarkNotificationUnreadForUserAction;
use App\Actions\Notification\PrepareNotificationOpenRedirectForUserAction;
use App\Http\Middleware\ValidateWorkOSSession;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

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

test('mark unread action clears read_at for owned notification', function (): void {
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
        'read_at' => now(),
    ]);

    $ok = app(MarkNotificationUnreadForUserAction::class)->execute($user, $notification->id);

    expect($ok)->toBeTrue()
        ->and($notification->fresh()->read_at)->toBeNull();
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
