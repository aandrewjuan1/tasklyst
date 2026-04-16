<?php

use App\Http\Requests\AuthKitAuthenticationRequest;
use App\Models\User as AppUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\WorkOS\User as WorkOsUser;

uses(RefreshDatabase::class);

test('findUsing reuses an existing user by email when workos id changes', function (): void {
    $existingUser = AppUser::factory()->create([
        'email' => 'aandrewjuan2@gmail.com',
        'workos_id' => 'old_workos_id',
    ]);

    $request = new AuthKitAuthenticationRequest;

    $workOsUser = new WorkOsUser(
        id: 'user_01KPBCBXNRQJAGZSWCDREP7ED2',
        firstName: 'Andrew',
        lastName: 'Juan',
        email: 'aandrewjuan2@gmail.com',
        avatar: 'https://example.com/avatar.png',
    );

    $method = new ReflectionMethod(AuthKitAuthenticationRequest::class, 'findUsing');
    $method->setAccessible(true);

    $resolvedUser = $method->invoke($request, $workOsUser);

    expect($resolvedUser?->is($existingUser))->toBeTrue();
});

test('findUsing prefers matching by workos id over email', function (): void {
    $workosIdUser = AppUser::factory()->create([
        'email' => 'other@example.com',
        'workos_id' => 'user_01KPBCBXNRQJAGZSWCDREP7ED2',
    ]);

    AppUser::factory()->create([
        'email' => 'aandrewjuan2@gmail.com',
        'workos_id' => 'old_workos_id',
    ]);

    $request = new AuthKitAuthenticationRequest;

    $workOsUser = new WorkOsUser(
        id: 'user_01KPBCBXNRQJAGZSWCDREP7ED2',
        firstName: 'Andrew',
        lastName: 'Juan',
        email: 'aandrewjuan2@gmail.com',
        avatar: 'https://example.com/avatar.png',
    );

    $method = new ReflectionMethod(AuthKitAuthenticationRequest::class, 'findUsing');
    $method->setAccessible(true);

    $resolvedUser = $method->invoke($request, $workOsUser);

    expect($resolvedUser?->is($workosIdUser))->toBeTrue();
});

test('updateUsing syncs the workos id for an existing email-matched user', function (): void {
    $existingUser = AppUser::factory()->create([
        'email' => 'aandrewjuan2@gmail.com',
        'workos_id' => 'old_workos_id',
        'avatar' => '',
    ]);

    $request = new AuthKitAuthenticationRequest;

    $workOsUser = new WorkOsUser(
        id: 'user_01KPBCBXNRQJAGZSWCDREP7ED2',
        firstName: 'Andrew',
        lastName: 'Juan',
        email: 'aandrewjuan2@gmail.com',
        avatar: 'https://example.com/avatar.png',
    );

    $method = new ReflectionMethod(AuthKitAuthenticationRequest::class, 'updateUsing');
    $method->setAccessible(true);

    /** @var AppUser $updatedUser */
    $updatedUser = $method->invoke($request, $existingUser, $workOsUser);

    expect($updatedUser->workos_id)->toBe('user_01KPBCBXNRQJAGZSWCDREP7ED2');
    expect($updatedUser->avatar)->toBe('https://example.com/avatar.png');
    expect($updatedUser->email)->toBe('aandrewjuan2@gmail.com');
});
