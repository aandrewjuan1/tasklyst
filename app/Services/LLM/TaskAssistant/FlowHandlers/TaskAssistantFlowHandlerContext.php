<?php

namespace App\Services\LLM\TaskAssistant\FlowHandlers;

use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use App\Services\LLM\TaskAssistant\ExecutionPlan;

final readonly class TaskAssistantFlowHandlerContext
{
    public function __construct(
        public TaskAssistantThread $thread,
        public TaskAssistantMessage $userMessage,
        public TaskAssistantMessage $assistantMessage,
        public string $content,
        public ExecutionPlan $plan,
    ) {}
}
