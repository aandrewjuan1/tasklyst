<?php

use Illuminate\Support\Facades\Http;

it('forwards prism style generate requests to local ollama upstream', function (): void {
    config()->set('services.ai_proxy.upstream_url', 'http://127.0.0.1:11434');

    Http::fake([
        'http://127.0.0.1:11434/api/generate' => Http::response([
            'response' => 'hello from compat route',
            'done' => true,
        ]),
    ]);

    $response = $this->postJson('/api/ollama/api/generate', [
        'model' => 'hermes3:3b',
        'prompt' => 'hello',
        'stream' => false,
    ]);

    $response->assertSuccessful();
    $response->assertJson([
        'response' => 'hello from compat route',
        'done' => true,
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        return $request->url() === 'http://127.0.0.1:11434/api/generate'
            && $request['model'] === 'hermes3:3b'
            && $request['prompt'] === 'hello'
            && $request['stream'] === false;
    });
});

it('forwards ollama tags checks through compat route', function (): void {
    config()->set('services.ai_proxy.upstream_url', 'http://127.0.0.1:11434');

    Http::fake([
        'http://127.0.0.1:11434/api/tags' => Http::response([
            'models' => [
                ['name' => 'hermes3:3b'],
            ],
        ]),
    ]);

    $response = $this->get('/api/ollama/api/tags');

    $response->assertSuccessful();
    $response->assertJson([
        'models' => [
            ['name' => 'hermes3:3b'],
        ],
    ]);

    Http::assertSent(fn (\Illuminate\Http\Client\Request $request): bool => $request->url() === 'http://127.0.0.1:11434/api/tags');
});
