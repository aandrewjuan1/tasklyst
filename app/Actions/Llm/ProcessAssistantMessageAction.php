<?php

namespace App\Actions\Llm;

use App\Models\AssistantMessage;
use App\Models\User;
use App\Services\Llm\RecommendationDisplayBuilder;

class ProcessAssistantMessageAction
{
    public function __construct(
        private GetOrCreateAssistantThreadAction $getOrCreateThread,
        private AppendAssistantMessageAction $appendMessage,
        private ClassifyLlmIntentAction $classifyIntent,
        private RunLlmInferenceAction $runInference,
        private RecommendationDisplayBuilder $displayBuilder,
    ) {}

    public function execute(User $user, string $userMessage, ?int $threadId = null): AssistantMessage
    {
        $thread = $this->getOrCreateThread->execute($user, $threadId);
        $this->appendMessage->execute($thread, 'user', $userMessage);

        $classification = $this->classifyIntent->execute($userMessage);
        $intent = $classification->intent;
        $entityType = $classification->entityType;

        $inferenceResult = $this->runInference->execute(
            $user,
            $userMessage,
            $intent,
            $entityType,
            null,
            $thread
        );

        $display = $this->displayBuilder->build($inferenceResult, $intent, $entityType);
        $content = $display->recommendedAction;

        $metadata = [
            'intent' => $intent->value,
            'entity_type' => $entityType->value,
            'recommendation_snapshot' => $display->toArray(),
        ];

        return $this->appendMessage->execute($thread, 'assistant', $content, $metadata);
    }
}
