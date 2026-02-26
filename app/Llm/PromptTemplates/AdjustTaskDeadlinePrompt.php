<?php

namespace App\Llm\PromptTemplates;

class AdjustTaskDeadlinePrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a task scheduling assistant. Goal: suggest a new deadline or time for an existing task when the user asks to move, extend, or delay it. '
            .self::RECURRING_CONSTRAINT.' '
            .'Consider dependent tasks and conflicts. Recommend start_datetime and end_datetime with reasoning. '
            .$this->outputAndGuardrails(true);
    }
}
