<?php

use App\Services\LLM\Prism\OllamaProxyProvider;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

test('ollama provider resolves to proxy provider extension', function (): void {
    config()->set('prism.providers.ollama.url', 'https://example.test/proxy.php');
    config()->set('prism.providers.ollama.api_key', 'shared-token');

    $provider = Prism::provider(Provider::Ollama);

    expect($provider)->toBeInstanceOf(OllamaProxyProvider::class);
});
