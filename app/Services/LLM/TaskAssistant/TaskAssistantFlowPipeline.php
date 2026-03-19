<?php

namespace App\Services\LLM\TaskAssistant;

use App\Enums\TaskAssistantIntent;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use App\Services\LLM\Intent\IntentClassificationService;

final class TaskAssistantFlowPipeline
{
    public function __construct(
        private readonly IntentClassificationService $intentClassifier,
        private readonly TaskAssistantSnapshotService $snapshotService,
        private readonly TaskAssistantContextAnalyzer $contextAnalyzer,
        private readonly TaskAssistantHistoryPrismMessageBuilder $historyBuilder,
    ) {}

    public function buildContext(
        TaskAssistantThread $thread,
        TaskAssistantMessage $userMessage,
        TaskAssistantMessage $assistantMessage,
        ?TaskAssistantIntent $edgeIntent = null
    ): TaskAssistantRequestContext {
        $user = $thread->user;
        $userMessageContent = trim((string) ($userMessage->content ?? ''));

        $intent = $edgeIntent ?? $this->intentClassifier->classify($userMessageContent);
        $flow = $this->intentClassifier->getFlowForIntent($intent);

        return new TaskAssistantRequestContext(
            thread: $thread,
            userMessage: $userMessage,
            assistantMessage: $assistantMessage,
            user: $user,
            userMessageContent: $userMessageContent,
            intent: $intent,
            flow: $flow,
            snapshotService: $this->snapshotService,
            contextAnalyzer: $this->contextAnalyzer,
            historyBuilder: $this->historyBuilder,
        );
    }
}
