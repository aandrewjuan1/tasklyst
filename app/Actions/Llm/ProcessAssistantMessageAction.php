<?php

namespace App\Actions\Llm;

use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Jobs\Llm\RunLlmInferenceJob;
use App\Models\AssistantMessage;
use App\Models\User;
use App\Services\Llm\QueryRelevanceService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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

        if ($this->queryRelevance->isSocialClosing($userMessage)) {
            $content = __('You\'re welcome! Good luck with your tasks and have a productive day. Feel free to come back anytime you need help with your schedule or priorities.');

            $metadata = [
                'intent' => LlmIntent::GeneralQuery->value,
                'entity_type' => LlmEntityType::Task->value,
                'recommendation_snapshot' => [
                    'used_guardrail' => true,
                    'reasoning' => 'social_closing',
                ],
            ];

            return $this->appendMessage->execute($thread, 'assistant', $content, $metadata);
        }

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

        if ($this->isRateLimited($user)) {
            $content = __('You\'ve sent quite a few requests in a short time. Please wait a minute and try again so I can keep responses quick for everyone.');

            $metadata = [
                'intent' => LlmIntent::GeneralQuery->value,
                'entity_type' => LlmEntityType::Task->value,
                'recommendation_snapshot' => [
                    'used_guardrail' => true,
                    'reasoning' => 'rate_limited',
                ],
            ];

            return $this->appendMessage->execute($thread, 'assistant', $content, $metadata);
        }

        $this->incrementRateLimitCounter($user);

        $traceId = Str::uuid()->toString();
        $classification = $this->classifyIntent->execute($userMessage, $thread, $traceId);
        $intent = $classification->intent;
        $entityType = $classification->entityType;

        RunLlmInferenceJob::dispatch(
            userId: $user->id,
            threadId: $thread->id,
            userMessage: $userMessage,
            intent: $intent->value,
            entityType: $entityType->value,
            traceId: $traceId
        );

        $metadata = $userMessageModel->metadata ?? [];
        $metadata['llm_trace_id'] = $traceId;
        $userMessageModel->metadata = $metadata;
        $userMessageModel->save();

        return $userMessageModel;
    }

    private function isRateLimited(User $user): bool
    {
        if (! (bool) config('tasklyst.guardrails.rate_limit_enabled', false)) {
            return false;
        }

        $limit = max(1, (int) config('tasklyst.guardrails.rate_limit_per_minute', 30));
        $key = 'tasklyst_llm_requests:'.$user->id;

        return (int) Cache::get($key, 0) >= $limit;
    }

    private function incrementRateLimitCounter(User $user): void
    {
        if (! (bool) config('tasklyst.guardrails.rate_limit_enabled', false)) {
            return;
        }

        $key = 'tasklyst_llm_requests:'.$user->id;
        Cache::add($key, 0, 60);
        Cache::increment($key);
    }
}
