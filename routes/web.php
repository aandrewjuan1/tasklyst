<?php

use App\Http\Middleware\ValidateWorkOSSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Messages\UserMessage;

Route::middleware([
    'auth',
    ValidateWorkOSSession::class,
])->group(function () {
    Route::livewire('/', 'pages::dashboard.index');
    Route::livewire('dashboard', 'pages::dashboard.index')->name('dashboard');
    Route::livewire('workspace', 'pages::workspace.index')->name('workspace');

    Route::get('llm/prompt-test', function (Request $request) {
        $prompt = trim((string) $request->query('prompt', ''));
        if ($prompt === '') {
            return response()->json([
                'ok' => false,
                'error' => 'prompt_required',
            ], 422);
        }

        $schema = new ObjectSchema(
            name: 'prompt_test_response',
            description: 'Simple response envelope for Prism prompt connectivity checks.',
            properties: [
                new StringSchema(
                    name: 'reply',
                    description: 'A concise assistant response to the provided prompt.'
                ),
            ],
            requiredFields: ['reply']
        );

        $response = Prism::structured()
            ->using(Provider::Ollama, (string) config('task-assistant.model', 'hermes3:3b'))
            ->withMessages([new UserMessage($prompt)])
            ->withSchema($schema)
            ->withTools([])
            ->asStructured();

        $payload = $response->structured ?? [];

        return response()->json([
            'ok' => true,
            'provider' => 'ollama',
            'model' => (string) config('task-assistant.model', 'hermes3:3b'),
            'prompt' => $prompt,
            'reply' => (string) ($payload['reply'] ?? ''),
            'raw' => $payload,
        ]);
    })->name('llm.prompt-test');
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
