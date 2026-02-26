<?php

namespace App\Jobs\Llm;

use App\Actions\Llm\AppendAssistantMessageAction;
use App\Actions\Llm\RunLlmInferenceAction;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Models\AssistantThread;
use App\Models\User;
use App\Services\Llm\RecommendationDisplayBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunLlmInferenceJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $userId,
        public int $threadId,
        public string $userMessage,
        public string $intent,
        public string $entityType
    ) {
        $this->onQueue(config('tasklyst.llm.queue', 'llm'));
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $user = User::find($this->userId);
        $thread = AssistantThread::find($this->threadId);

        if ($user === null || $thread === null) {
            return;
        }

        $intent = LlmIntent::tryFrom($this->intent);
        $entityType = LlmEntityType::tryFrom($this->entityType);

        if ($intent === null || $entityType === null) {
            return;
        }

        /** @var RunLlmInferenceAction $runInference */
        $runInference = app(RunLlmInferenceAction::class);
        /** @var RecommendationDisplayBuilder $displayBuilder */
        $displayBuilder = app(RecommendationDisplayBuilder::class);
        /** @var AppendAssistantMessageAction $appendMessage */
        $appendMessage = app(AppendAssistantMessageAction::class);

        $inferenceResult = $runInference->execute(
            user: $user,
            userMessage: $this->userMessage,
            intent: $intent,
            entityType: $entityType,
            entityId: null,
            thread: $thread
        );

        $display = $displayBuilder->build($inferenceResult, $intent, $entityType);

        $metadata = [
            'intent' => $intent->value,
            'entity_type' => $entityType->value,
            'recommendation_snapshot' => $display->toArray(),
        ];

        $appendMessage->execute($thread, 'assistant', $display->recommendedAction, $metadata);
    }
}
