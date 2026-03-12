<?php

namespace App\Services\Llm;

class TokenBudgetReducer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function reduce(array $payload): array
    {
        $maxTokens = (int) config('tasklyst.context.max_tokens', 1200);
        $ratio = (float) config('tasklyst.context.safety_margin_ratio', 0.9);
        $cap = (int) floor($maxTokens * $ratio);

        while ($this->estimateTokens($payload) > $cap && isset($payload['conversation_history']) && is_array($payload['conversation_history']) && $payload['conversation_history'] !== []) {
            $payload['conversation_history'] = array_slice($payload['conversation_history'], 1);
        }

        if ($this->estimateTokens($payload) <= $cap) {
            return $payload;
        }

        foreach (['tasks', 'events', 'projects'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key]) && count($payload[$key]) > 4) {
                $payload[$key] = array_slice($payload[$key], 0, 4);
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function estimateTokens(array $payload): int
    {
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            return PHP_INT_MAX;
        }

        return (int) (strlen($json) / 4);
    }
}
