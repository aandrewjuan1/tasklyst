<?php

use App\Http\Middleware\ValidateWorkOSSession;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth',
    ValidateWorkOSSession::class,
])->group(function () {
    Route::livewire('/', 'pages::workspace.index');
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('workspace', 'pages::workspace.index')->name('workspace');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
