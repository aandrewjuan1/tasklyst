<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\WorkOS\Http\Requests\AuthKitAuthenticationRequest;
use Laravel\WorkOS\Http\Requests\AuthKitLogoutRequest;
use Laravel\WorkOS\WorkOS;
use WorkOS\UserManagement;

$loginHandler = function (Request $request) {
    if (Auth::check()) {
        return redirect()->route('dashboard');
    }

    if ($request->boolean('redirect')) {
        WorkOS::configure();

        $redirectUrl = config('services.workos.redirect_url') ?: route('workos.authenticate');
        $provider = (string) config('services.workos.provider', 'authkit');

        $url = (new UserManagement)->getAuthorizationUrl(
            $redirectUrl,
            ['state' => $state = Str::random(20)],
            $provider,
        );

        $request->session()->put('state', $state);

        $inertiaClass = 'Inertia\\Inertia';

        return class_exists($inertiaClass)
            ? $inertiaClass::location($url)
            : redirect($url);
    }

    return view('auth.login');
};

Route::get('login', $loginHandler)->name('login');
Route::get('signin', $loginHandler);

Route::get('authenticate', function (AuthKitAuthenticationRequest $request) {
    return tap(redirect()->intended(route('dashboard')), fn () => $request->authenticate());
})->middleware(['guest'])->name('workos.authenticate');

Route::post('logout', function (AuthKitLogoutRequest $request) {
    return $request->logout();
})->middleware(['auth'])->name('logout');
