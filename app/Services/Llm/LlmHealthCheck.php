<?php

namespace App\Services\Llm;

use Illuminate\Support\Facades\Http;

/**
 * Check if Ollama is reachable before attempting Phase 5 inference.
 * Prevents long timeout waits when Ollama is down.
 */
class LlmHealthCheck
{
    public function isReachable(): bool
    {
        $url = config('prism.providers.ollama.url', 'http://localhost:11434');
        $base = rtrim($url, '/');
        if (str_ends_with($base, '/v1')) {
            $base = substr($base, 0, -3);
        }

        try {
            $response = Http::timeout(3)->get($base.'/api/tags');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
