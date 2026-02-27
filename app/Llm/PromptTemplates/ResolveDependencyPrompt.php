<?php

namespace App\Llm\PromptTemplates;

class ResolveDependencyPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a dependency resolution assistant helping a student untangle what is blocking their progress across tasks, events, and projects. '
            .'Goal: help them understand blocking relationships and suggest a clear order of work or next steps. '
            .'Use an internal reasoning process: (1) identify blockers and what they are blocking (2) determine which prerequisites can be completed soon (3) propose a simple sequence of next steps that will unblock the most important work. '
            .'In your JSON output, set recommended_action to a friendly summary of what the student should do first to get unstuck, and set reasoning to a brief explanation of how you chose that order. '
            .$this->outputAndGuardrails(false);
    }
}
