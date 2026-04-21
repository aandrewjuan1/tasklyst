<?php

namespace App\Services\LLM;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class OllamaProxyClient
{
    public function generate(string $prompt, ?string $model = null): Response
    {
        $proxyUrl = trim((string) config('services.ollama_proxy.url', ''));
        $proxyToken = (string) config('services.ollama_proxy.token', '');

        if ($proxyUrl === '' || $proxyToken === '') {
            throw new RuntimeException('Ollama proxy is not configured.');
        }

        /** @var Response $response */
        $response = Http::timeout((int) config('prism.request_timeout', 120))
            ->post($proxyUrl, [
                'prompt' => $prompt,
                'model' => $model ?? (string) config('services.ollama_proxy.default_model', 'hermes3:3b'),
                'token' => $proxyToken,
            ]);

        return $response;
    }
}
