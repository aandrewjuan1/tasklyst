<?php

use App\Http\Requests\AuthKitLogoutRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\RedirectResponse;

uses(RefreshDatabase::class);

test('logout invalidates the session and regenerates the csrf token when no workos session exists', function (): void {
    $session = Mockery::mock();
    $session->shouldReceive('get')->with('workos_access_token')->andReturn(null);
    $session->shouldReceive('invalidate')->once();
    $session->shouldReceive('regenerateToken')->once();

    $guard = Mockery::mock();
    $guard->shouldReceive('logout')->once();

    Auth::shouldReceive('guard')
        ->with('web')
        ->andReturn($guard)
        ->once();

    /** @var AuthKitLogoutRequest $request */
    $request = Mockery::mock(AuthKitLogoutRequest::class)->makePartial();
    $request->shouldReceive('session')->andReturn($session);

    /** @var RedirectResponse $response */
    $response = $request->logout();

    expect($response)->toBeInstanceOf(RedirectResponse::class);
    expect($response->getTargetUrl())->toBe(route('login'));
});

test('logout route redirects guest users to the login page', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'), [
        '_token' => csrf_token(),
    ]);

    $response->assertRedirect(route('login'));
    $this->assertGuest();
});
