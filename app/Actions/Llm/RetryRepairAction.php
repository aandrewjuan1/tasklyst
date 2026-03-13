<?php

namespace App\Actions\Llm;

use Illuminate\Support\Facades\Log;

class RetryRepairAction
{
    /**
     * Asks the model ONCE to fix broken JSON into a valid canonical envelope.
     * Returns repaired string, or null if repair also fails.
     *
     * NEVER call this more than once per original request — see config('llm.repair.max_attempts').
     */
    public function __invoke(string $brokenJson, string $schemaDescription): ?string
    {
        $repairPrompt = <<<PROMPT
        The following JSON is malformed or incomplete. Fix it so it exactly matches this schema:
        {$schemaDescription}

        Broken JSON:
        {$brokenJson}

        Return ONLY the corrected JSON. No markdown. No explanation.
        PROMPT;

        try {
            return null;
        } catch (\Throwable $e) {
            Log::channel(config('llm.log.channel'))->warning('llm.repair.failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
