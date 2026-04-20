<?php

namespace App\Services\LLM\TaskAssistant\FlowHandlers;

use App\Services\LLM\TaskAssistant\TaskAssistantService;

final class ScheduleFlowHandler implements TaskAssistantFlowHandler
{
    public function __construct(
        private readonly TaskAssistantService $service,
    ) {}

    public function handle(TaskAssistantFlowHandlerContext $context): void
    {
        $this->service->executeScheduleFlow($context);
    }
}
