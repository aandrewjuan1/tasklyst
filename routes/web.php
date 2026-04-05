<?php

use App\Http\Controllers\DashboardController;
use App\Http\Middleware\ValidateWorkOSSession;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth',
    ValidateWorkOSSession::class,
])->group(function () {
    Route::livewire('/', 'pages::workspace.index');
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::livewire('workspace', 'pages::workspace.index')->name('workspace');
});

// Test-only routes for ConnectCalendarFeedJobTest.
Route::get('/tests/unit/connectcalendarfeedjob', function () {
    return 'ConnectCalendarFeedJob test page';
});

Route::get('/tests/feature/tests/unit/connectcalendarfeedjob', function () {
    return 'ConnectCalendarFeedJob feature test page';
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
