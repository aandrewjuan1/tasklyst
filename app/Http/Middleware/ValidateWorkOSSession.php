<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Laravel\WorkOS\WorkOS;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use WorkOS\Exception\WorkOSException;

class ValidateWorkOSSession
{
    private const CACHE_KEY_PREFIX = 'workos_session_valid:';

    /**
     * Handle an incoming request.
     * Validates the WorkOS session and caches the result per session to avoid
     * calling the WorkOS API on every request (reduces latency and timeouts).
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        if (app()->runningUnitTests() || app()->environment('testing')) {
            return $next($request);
        }

        WorkOS::configure();

        if (! $request->session()->get('workos_access_token') ||
            ! $request->session()->get('workos_refresh_token')) {
            return $this->logout($request);
        }

        $cacheKey = self::CACHE_KEY_PREFIX.$request->session()->getId();
        $ttlMinutes = config('services.workos.session_validation_cache_ttl_minutes', 5);

        if (Cache::has($cacheKey)) {
            return $next($request);
        }

        try {
            [$accessToken, $refreshToken] = WorkOS::ensureAccessTokenIsValid(
                $request->session()->get('workos_access_token'),
                $request->session()->get('workos_refresh_token'),
            );

            $request->session()->put('workos_access_token', $accessToken);
            $request->session()->put('workos_refresh_token', $refreshToken);

            Cache::put($cacheKey, true, now()->addMinutes($ttlMinutes));
        } catch (WorkOSException $e) {
            report($e);

            return $this->logout($request);
        }

        return $next($request);
    }

    protected function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
