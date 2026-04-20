<?php

namespace App\Services\LLM\TaskAssistant\FlowHandlers;

use App\Support\LLM\TaskAssistantFlowNames;
use UnexpectedValueException;

final class TaskAssistantFlowHandlerRegistry
{
    public function resolve(string $flow): TaskAssistantFlowHandler
    {
        return match ($flow) {
            TaskAssistantFlowNames::PRIORITIZE => app(PrioritizeFlowHandler::class),
            TaskAssistantFlowNames::SCHEDULE => app(ScheduleFlowHandler::class),
            TaskAssistantFlowNames::PRIORITIZE_SCHEDULE => app(PrioritizeScheduleFlowHandler::class),
            TaskAssistantFlowNames::SCHEDULE_REFINEMENT => app(ScheduleRefinementFlowHandler::class),
            default => throw new UnexpectedValueException('No flow handler registered for flow: '.$flow),
        };
    }
}
