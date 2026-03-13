<?php

use App\Http\Controllers\ChatThreadController;
use App\Http\Middleware\ValidateWorkOSSession;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth',
    ValidateWorkOSSession::class,
])->group(function () {
    Route::livewire('/', 'pages::workspace.index');
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('workspace', 'pages::workspace.index')->name('workspace');

    Route::prefix('chat')
        ->middleware([
            'throttle:'.config('llm.rate_limit.max_requests').','.config('llm.rate_limit.per_minutes'),
        ])
        ->group(function (): void {
            Route::post('/threads', [ChatThreadController::class, 'store']);
            Route::post('/threads/{thread}/messages', [ChatThreadController::class, 'sendMessage']);
            Route::get('/threads/{thread}/messages', [ChatThreadController::class, 'messages']);
            Route::patch('/threads/{thread}', [ChatThreadController::class, 'update']);
            Route::delete('/threads/{thread}', [ChatThreadController::class, 'destroy']);
        });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
