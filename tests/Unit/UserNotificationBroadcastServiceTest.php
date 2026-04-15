<?php

use App\Events\UserNotificationCreated;
use App\Models\User;
use App\Services\UserNotificationBroadcastService;
use Illuminate\Contracts\Events\Dispatcher;

test('broadcast inbox updated dispatches user notification created event', function (): void {
    $user = User::factory()->create();

    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher
        ->shouldReceive('dispatch')
        ->once()
        ->with(Mockery::on(function (mixed $event) use ($user): bool {
            return $event instanceof UserNotificationCreated
                && $event->userId === $user->id;
        }))
        ->andReturnTrue();

    $service = new UserNotificationBroadcastService($dispatcher);
    $service->broadcastInboxUpdated($user);

    expect(true)->toBeTrue();
});

test('broadcast inbox updated does not throw when dispatcher fails', function (): void {
    $user = User::factory()->create();

    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher
        ->shouldReceive('dispatch')
        ->once()
        ->andThrow(new RuntimeException('Broadcast service unavailable.'));

    $service = new UserNotificationBroadcastService($dispatcher);
    $service->broadcastInboxUpdated($user);

    expect(true)->toBeTrue();
});
