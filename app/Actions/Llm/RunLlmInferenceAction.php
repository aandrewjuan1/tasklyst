<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\LlmInferenceResult;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Models\AssistantThread;
use App\Models\User;
use App\Services\Llm\LlmHealthCheck;
use App\Services\Llm\LlmInteractionLogger;
use App\Services\Llm\StructuredOutputSanitizer;
use App\Services\LlmInferenceService;
use Illuminate\Support\Str;

class RunLlmInferenceAction
{
    public function __construct(
        private GetSystemPromptAction $getSystemPrompt,
        private BuildLlmContextAction $buildContext,
        private LlmInferenceService $inferenceService,
        private LlmHealthCheck $healthCheck,
        private LlmInteractionLogger $interactionLogger,
        private StructuredOutputSanitizer $sanitizer,
    ) {}

    public function execute(
        User $user,
        string $userMessage,
        LlmIntent $intent,
        LlmEntityType $entityType,
        ?int $entityId = null,
        ?AssistantThread $thread = null,
        ?string $traceId = null,
    ): LlmInferenceResult {
        $promptResult = $this->getSystemPrompt->execute($intent);

        if (! $this->healthCheck->isReachable()) {
            $result = $this->inferenceService->fallbackOnly(
                intent: $intent,
                promptVersion: $promptResult->version,
                user: $user,
                fallbackReason: 'health_unreachable',
            );

            $this->interactionLogger->logInference(
                user: $user,
                intent: $intent,
                entityType: $entityType,
                promptResult: $promptResult,
                inferenceResult: $result,
                context: [],
                durationMs: 0,
                llmReachable: false,
                traceId: $traceId,
            );

            return $result;
        }

        $context = $this->buildContext->execute($user, $intent, $entityType, $entityId, $thread);

        $contextJson = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $userPrompt = Str::limit($userMessage, 2000)."\n\nContext:\n".$contextJson;

        $startedAt = microtime(true);

        $result = $this->inferenceService->infer(
            systemPrompt: $promptResult->systemPrompt,
            userPrompt: $userPrompt,
            intent: $intent,
            promptResult: $promptResult,
            user: $user,
        );

        $sanitizedStructured = $this->sanitizer->sanitize($result->structured, $context, $intent);
        $result = new LlmInferenceResult(
            structured: $sanitizedStructured,
            promptVersion: $result->promptVersion,
            promptTokens: $result->promptTokens,
            completionTokens: $result->completionTokens,
            usedFallback: $result->usedFallback,
            fallbackReason: $result->fallbackReason,
        );

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        $this->interactionLogger->logInference(
            user: $user,
            intent: $intent,
            entityType: $entityType,
            promptResult: $promptResult,
            inferenceResult: $result,
            context: $context,
            durationMs: $durationMs,
            llmReachable: true,
            traceId: $traceId,
        );

        return $result;
    }
}
