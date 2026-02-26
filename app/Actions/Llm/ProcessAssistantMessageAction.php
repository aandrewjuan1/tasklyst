<?php

namespace App\Actions\Llm;

use App\Jobs\Llm\RunLlmInferenceJob;
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
        $userMessageModel = $this->appendMessage->execute($thread, 'user', $userMessage);

        $classification = $this->classifyIntent->execute($userMessage);
        $intent = $classification->intent;
        $entityType = $classification->entityType;

        $useQueue = (bool) config('tasklyst.llm.use_queue', false);

        if ($useQueue) {
            RunLlmInferenceJob::dispatch(
                userId: $user->id,
                threadId: $thread->id,
                userMessage: $userMessage,
                intent: $intent->value,
                entityType: $entityType->value
            );

            return $userMessageModel;
        }

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
