<?php

namespace App\Actions\Llm;

use Illuminate\Support\Facades\Log;

class RetryRepairAction
{
    /**
     * Attempts to repair broken JSON into a valid canonical envelope string
     * using lightweight, non-LLM heuristics.
     *
     * Returns repaired string, or null if repair fails.
     *
     * NEVER call this more than once per original request — see config('llm.repair.max_attempts').
     */
    public function __invoke(string $brokenJson, string $schemaDescription): ?string
    {
        try {
            $candidate = $this->extractCandidate($brokenJson);

            if ($candidate === null) {
                return null;
            }

            $candidate = $this->normalizeCandidate($candidate);
            $decoded = json_decode($candidate, true);

            if (! is_array($decoded)) {
                return null;
            }

            if (array_is_list($decoded)) {
                $first = $decoded[0] ?? null;
                if (! is_array($first)) {
                    return null;
                }

                return json_encode($first, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            Log::channel(config('llm.log.channel'))->warning('llm.repair.failed', [
                'error' => $e->getMessage(),
                'schema' => $schemaDescription,
            ]);

            return null;
        }
    }

    private function extractCandidate(string $brokenJson): ?string
    {
        $cleaned = trim($brokenJson);
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s*```$/', '', $cleaned) ?? $cleaned;

        if ($cleaned === '') {
            return null;
        }

        if (str_starts_with($cleaned, '{') && str_ends_with($cleaned, '}')) {
            return $cleaned;
        }

        if (str_starts_with($cleaned, '[') && str_ends_with($cleaned, ']')) {
            return $cleaned;
        }

        $firstBrace = strpos($cleaned, '{');
        $lastBrace = strrpos($cleaned, '}');

        if ($firstBrace === false || $lastBrace === false || $lastBrace <= $firstBrace) {
            return null;
        }

        return substr($cleaned, $firstBrace, ($lastBrace - $firstBrace) + 1);
    }

    private function normalizeCandidate(string $candidate): string
    {
        $normalized = str_replace(
            ["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}"],
            ['"', '"', "'", "'"],
            $candidate
        );

        $normalized = preg_replace('/,\s*([}\]])/', '$1', $normalized) ?? $normalized;

        return trim($normalized);
    }
}
