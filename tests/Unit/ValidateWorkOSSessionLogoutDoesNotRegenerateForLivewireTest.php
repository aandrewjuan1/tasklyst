<?php

use App\Http\Middleware\ValidateWorkOSSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\RedirectResponse;

it('invalidates the session and redirects to login when workos session validation fails', function (): void {
    $middleware = new ValidateWorkOSSession;

    $request = Mockery::mock(Request::class);
    $session = Mockery::mock();
    $session->shouldReceive('invalidate')->once();
    $session->shouldReceive('regenerateToken')->once();

    $request->shouldReceive('session')->andReturn($session);

    $guard = Mockery::mock();
    $guard->shouldReceive('logout')->once();

    Auth::shouldReceive('guard')
        ->with('web')
        ->andReturn($guard)
        ->once();

    $method = new ReflectionMethod(ValidateWorkOSSession::class, 'logout');
    $method->setAccessible(true);

    $response = $method->invoke($middleware, $request);

    expect($response)->toBeInstanceOf(RedirectResponse::class);
    expect($response->getTargetUrl())->toBe(route('login'));
});
