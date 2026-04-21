<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

test('authenticated users can run the llm proxy test route', function (): void {
    config()->set('services.ollama_proxy.url', 'https://friend.trycloudflare.com/api/ai/proxy');
    config()->set('services.ollama_proxy.token', 'shared-token');
    config()->set('services.ollama_proxy.default_model', 'hermes3:3b');

    Http::fake([
        'https://friend.trycloudflare.com/api/ai/proxy' => Http::response([
            'response' => 'Hello from remote proxy',
            'done' => true,
        ]),
    ]);

    /** @var User&\Illuminate\Contracts\Auth\Authenticatable $user */
    $user = User::factory()->createOne();

    $response = $this->actingAs($user)->get('/llm/proxy-test?prompt=Say%20hello');

    $response->assertSuccessful();
    $response->assertJson([
        'ok' => true,
        'provider' => 'ollama_proxy',
        'prompt' => 'Say hello',
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        return $request->url() === 'https://friend.trycloudflare.com/api/ai/proxy'
            && $request['prompt'] === 'Say hello'
            && $request['model'] === 'hermes3:3b'
            && $request['token'] === 'shared-token';
    });
});

test('llm proxy test route validates prompt query parameter', function (): void {
    /** @var User&\Illuminate\Contracts\Auth\Authenticatable $user */
    $user = User::factory()->createOne();

    $response = $this->actingAs($user)->get('/llm/proxy-test');

    $response->assertUnprocessable();
    $response->assertJson([
        'ok' => false,
        'error' => 'prompt_required',
    ]);
});

test('llm proxy test route reports missing configuration', function (): void {
    config()->set('services.ollama_proxy.url', '');
    config()->set('services.ollama_proxy.token', '');

    /** @var User&\Illuminate\Contracts\Auth\Authenticatable $user */
    $user = User::factory()->createOne();

    $response = $this->actingAs($user)->get('/llm/proxy-test?prompt=Health%20check');

    $response->assertUnprocessable();
    $response->assertJson([
        'ok' => false,
        'error' => 'proxy_not_configured',
    ]);
});
