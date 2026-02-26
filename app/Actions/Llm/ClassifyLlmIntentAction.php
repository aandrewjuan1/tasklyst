<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\LlmIntentClassificationResult;
use App\Services\LlmIntentClassificationService;

class ClassifyLlmIntentAction
{
    public function __construct(
        private LlmIntentClassificationService $classificationService
    ) {}

    public function execute(string $userMessage): LlmIntentClassificationResult
    {
        $result = $this->classificationService->classify($userMessage);

        $threshold = config('tasklyst.intent.confidence_threshold', 0.7);
        $useLlmFallback = config('tasklyst.intent.use_llm_fallback', true);

        if ($useLlmFallback && $result->confidence < $threshold) {
            return $this->classifyWithLlmFallback($userMessage, $result);
        }

        return $result;
    }

    private function classifyWithLlmFallback(string $userMessage, LlmIntentClassificationResult $regexResult): LlmIntentClassificationResult
    {
        // Optional second-pass LLM classification (Phase 4 integration).
        // When LlmInferenceService is available, call a small Prism request here
        // with user message + short system prompt + schema { intent, entity_type }.
        // For now, return the regex result so behaviour is deterministic and testable.
        return $regexResult;
    }
}
