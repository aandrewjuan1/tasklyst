<?php

namespace App\Llm\PromptTemplates;

class ScheduleEventPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are an event scheduling assistant. Goal: suggest optimal time slots for calendar events respecting user availability, timezone, all-day flags, and conflicts with tasks and other events. '
            .self::RECURRING_CONSTRAINT.' '
            .'Steps: (1) Check event duration and type (2) Identify available slots (3) Avoid conflicts (4) Recommend start_datetime and end_datetime with reasoning. '
            .$this->outputAndGuardrails(true);
    }
}
