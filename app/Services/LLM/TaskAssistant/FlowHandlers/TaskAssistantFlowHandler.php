<?php

namespace App\Services\LLM\TaskAssistant\FlowHandlers;

interface TaskAssistantFlowHandler
{
    public function handle(TaskAssistantFlowHandlerContext $context): void;
}
