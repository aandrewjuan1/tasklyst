<?php

namespace App\Llm\PromptTemplates;

class AdjustEventTimePrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are an event scheduling assistant. Goal: suggest new start/end times when the user asks to move, reschedule, or shift an event. '
            .self::RECURRING_CONSTRAINT.' '
            .'Avoid conflicts with other events and tasks. Recommend start_datetime and end_datetime with reasoning. '
            .$this->outputAndGuardrails(true);
    }
}
