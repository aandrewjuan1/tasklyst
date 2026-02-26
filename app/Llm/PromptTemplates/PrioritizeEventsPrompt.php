<?php

namespace App\Llm\PromptTemplates;

class PrioritizeEventsPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are an event prioritization expert. Goal: rank events by importance considering timing, related tasks, and recurring vs one-time. '
            .'Steps: (1) Identify time-sensitive events (2) Consider related tasks (3) Output a prioritized event list with reasoning. '
            .$this->outputAndGuardrails(false);
    }
}
