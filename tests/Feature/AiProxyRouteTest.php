<?php

use Illuminate\Support\Facades\Http;

it('proxies ollama generate requests with a valid token', function (): void {
    config()->set('services.ai_proxy.token', 'proxy-secret');
    config()->set('services.ai_proxy.upstream_url', 'http://127.0.0.1:11434');
    config()->set('services.ai_proxy.default_model', 'hermes3:3b');

    Http::fake([
        'http://127.0.0.1:11434/api/generate' => Http::response([
            'response' => 'Hello from Hermes',
            'done' => true,
        ]),
    ]);

    $response = $this->postJson('/api/ai/proxy', [
        'prompt' => 'hello',
        'token' => 'proxy-secret',
    ]);

    $response->assertSuccessful();
    $response->assertJson([
        'response' => 'Hello from Hermes',
        'done' => true,
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        return $request->url() === 'http://127.0.0.1:11434/api/generate'
            && $request['prompt'] === 'hello'
            && $request['model'] === 'hermes3:3b'
            && $request['stream'] === false;
    });
});

it('rejects requests with an invalid proxy token', function (): void {
    config()->set('services.ai_proxy.token', 'proxy-secret');

    Http::fake();

    $response = $this->postJson('/api/ai/proxy', [
        'prompt' => 'hello',
        'token' => 'wrong-secret',
    ]);

    $response->assertUnauthorized();
    Http::assertNothingSent();
});

it('returns a gateway error when upstream ollama fails', function (): void {
    config()->set('services.ai_proxy.token', 'proxy-secret');
    config()->set('services.ai_proxy.upstream_url', 'http://127.0.0.1:11434');

    Http::fake([
        'http://127.0.0.1:11434/api/generate' => Http::response('upstream unavailable', 503),
    ]);

    $response = $this->postJson('/api/ai/proxy', [
        'prompt' => 'hello',
        'token' => 'proxy-secret',
    ]);

    $response->assertStatus(502);
    $response->assertJson([
        'ok' => false,
        'status' => 503,
        'error' => 'upstream unavailable',
    ]);
});
