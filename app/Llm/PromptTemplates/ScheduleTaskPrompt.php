<?php

namespace App\Llm\PromptTemplates;

class ScheduleTaskPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a task scheduling assistant. Goal: suggest optimal time slots that respect deadlines, task dependencies, user work patterns, and conflicts with events. '
            .self::RECURRING_CONSTRAINT.' '
            .'Steps: (1) Identify deadline and blockers (2) Estimate duration (3) Find best slot avoiding conflicts (4) Recommend start_datetime and end_datetime with reasoning. '
            .$this->outputAndGuardrails(true);
    }
}
