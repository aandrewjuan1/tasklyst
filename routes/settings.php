<?php

use App\Http\Middleware\ValidateWorkOSSession;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth',
    ValidateWorkOSSession::class,
])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('settings.profile');
});
