<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\LlmInferenceResult;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Models\AssistantThread;
use App\Models\User;
use App\Services\Llm\LlmHealthCheck;
use App\Services\LlmInferenceService;
use Illuminate\Support\Str;

class RunLlmInferenceAction
{
    public function __construct(
        private GetSystemPromptAction $getSystemPrompt,
        private BuildLlmContextAction $buildContext,
        private LlmInferenceService $inferenceService,
        private LlmHealthCheck $healthCheck,
    ) {}

    public function execute(
        User $user,
        string $userMessage,
        LlmIntent $intent,
        LlmEntityType $entityType,
        ?int $entityId = null,
        ?AssistantThread $thread = null,
    ): LlmInferenceResult {
        if (! $this->healthCheck->isReachable()) {
            $promptResult = $this->getSystemPrompt->execute($intent);

            return $this->inferenceService->fallbackOnly($intent, $promptResult->version, $user);
        }

        $promptResult = $this->getSystemPrompt->execute($intent);
        $context = $this->buildContext->execute($user, $intent, $entityType, $entityId, $thread);

        $contextJson = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $userPrompt = Str::limit($userMessage, 2000)."\n\nContext:\n".$contextJson;

        return $this->inferenceService->infer(
            systemPrompt: $promptResult->systemPrompt,
            userPrompt: $userPrompt,
            intent: $intent,
            promptResult: $promptResult,
            user: $user,
        );
    }
}
