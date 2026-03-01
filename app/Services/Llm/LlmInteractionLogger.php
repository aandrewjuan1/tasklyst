<?php

namespace App\Services\Llm;

use App\DataTransferObjects\Llm\LlmInferenceResult;
use App\DataTransferObjects\Llm\LlmSystemPromptResult;
use App\Enums\ActivityLogAction;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Models\User;
use App\Services\ActivityLogRecorder;

/**
 * Phase 9: log LLM interactions for analytics and thesis evaluation.
 *
 * Logs per-inference metadata (intent, entity_type, prompt_version, tokens, duration, fallback)
 * and a compact preview of the structured context payload.
 */
class LlmInteractionLogger
{
    public function __construct(
        private ActivityLogRecorder $activityLogRecorder,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function logInference(
        User $user,
        LlmIntent $intent,
        LlmEntityType $entityType,
        LlmSystemPromptResult $promptResult,
        LlmInferenceResult $inferenceResult,
        array $context,
        int $durationMs,
        bool $llmReachable,
        ?string $traceId = null,
    ): void {
        $contextJson = json_encode($context);

        $payload = [
            'intent' => $intent->value,
            'entity_type' => $entityType->value,
            'prompt_version' => $promptResult->version,
            'prompt_tokens' => $inferenceResult->promptTokens,
            'completion_tokens' => $inferenceResult->completionTokens,
            'used_fallback' => $inferenceResult->usedFallback,
            'fallback_reason' => $inferenceResult->fallbackReason,
            'duration_ms' => $durationMs,
            'llm_reachable' => $llmReachable,
            'context_size' => $contextJson !== false ? strlen($contextJson) : null,
            'context_preview' => $contextJson !== false
                ? mb_substr($contextJson, 0, 1000)
                : null,
        ];

        if ($traceId !== null) {
            $payload['trace_id'] = $traceId;
        }

        $this->activityLogRecorder->record(
            $user,
            $user,
            ActivityLogAction::LlmInteraction,
            $payload
        );
    }
}
