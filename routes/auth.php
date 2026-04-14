<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;
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

        $url = (new UserManagement)->getAuthorizationUrl(
            config('services.workos.redirect_url'),
            ['state' => $state = Str::random(20)],
            UserManagement::AUTHORIZATION_PROVIDER_GOOGLE_OAUTH,
        );

        $request->session()->put('state', $state);

        return class_exists(Inertia::class)
            ? Inertia::location($url)
            : redirect($url);
    }

    return view('auth.login');
};

Route::get('login', $loginHandler)->name('login');
Route::get('signin', $loginHandler);

Route::get('authenticate', function (AuthKitAuthenticationRequest $request) {
    return tap(redirect()->intended(route('dashboard')), fn () => $request->authenticate());
})->middleware(['guest']);

Route::post('logout', function (AuthKitLogoutRequest $request) {
    return $request->logout();
})->middleware(['auth'])->name('logout');
