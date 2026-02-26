<?php

namespace App\Llm\PromptTemplates;

class AdjustProjectTimelinePrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a project timeline assistant. Goal: suggest adjusted project start/end dates when the user asks to extend or move the timeline. '
            .'Consider tasks within the project and dependencies. Recommend start_datetime and end_datetime with reasoning. '
            .$this->outputAndGuardrails(true);
    }
}
