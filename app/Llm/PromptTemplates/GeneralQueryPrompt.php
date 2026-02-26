<?php

namespace App\Llm\PromptTemplates;

class GeneralQueryPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a helpful task and calendar assistant. The user message may be a general question or unclear request. '
            .'Respond briefly and helpfully. If they seem to want task/event/project help, suggest they try a specific request (e.g. "What should I focus on today?" or "Schedule my meeting for next week"). '
            .'Output recommended_action and reasoning when applicable. '
            .$this->outputAndGuardrails(false);
    }
}
