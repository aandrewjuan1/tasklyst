<?php

namespace App\Actions\Llm;

use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Jobs\Llm\RunLlmInferenceJob;
use App\Models\AssistantMessage;
use App\Models\User;
use App\Services\Llm\QueryRelevanceService;

class ProcessAssistantMessageAction
{
    public function __construct(
        private GetOrCreateAssistantThreadAction $getOrCreateThread,
        private AppendAssistantMessageAction $appendMessage,
        private ClassifyLlmIntentAction $classifyIntent,
        private QueryRelevanceService $queryRelevance,
    ) {}

    public function execute(User $user, string $userMessage, ?int $threadId = null): AssistantMessage
    {
        $thread = $this->getOrCreateThread->execute($user, $threadId);
        $userMessageModel = $this->appendMessage->execute($thread, 'user', $userMessage);

        $relevanceEnabled = (bool) config('tasklyst.guardrails.relevance_enabled', true);

        if ($relevanceEnabled && ! $this->queryRelevance->isRelevant($userMessage)) {
            $content = __('I’m focused on helping you manage your tasks, events, and projects as a student – things like planning your week, prioritising assignments, or scheduling study time. I can’t reliably answer general knowledge questions like that. Try asking something related to your work or schedule, for example: "What should I focus on today?" or "Help me plan my revision for next week."');

            $metadata = [
                'intent' => LlmIntent::GeneralQuery->value,
                'entity_type' => LlmEntityType::Task->value,
                'recommendation_snapshot' => [
                    'used_guardrail' => true,
                    'reasoning' => 'off_topic_query',
                ],
            ];

            return $this->appendMessage->execute($thread, 'assistant', $content, $metadata);
        }

        $classification = $this->classifyIntent->execute($userMessage);
        $intent = $classification->intent;
        $entityType = $classification->entityType;

        RunLlmInferenceJob::dispatch(
            userId: $user->id,
            threadId: $thread->id,
            userMessage: $userMessage,
            intent: $intent->value,
            entityType: $entityType->value
        );

        return $userMessageModel;
    }
}
