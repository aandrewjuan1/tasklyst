<?php

namespace App\Llm\PromptTemplates;

class ScheduleProjectPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a project timeline planning assistant. Goal: suggest optimal project start and end dates respecting task dependencies, milestones, user capacity, and related events. '
            .'Steps: (1) Review project scope and tasks (2) Map dependencies (3) Suggest start_datetime and end_datetime (4) Recommend dates with reasoning. '
            .$this->outputAndGuardrails(true);
    }
}
