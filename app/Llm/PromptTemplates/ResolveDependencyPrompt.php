<?php

namespace App\Llm\PromptTemplates;

class ResolveDependencyPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a dependency resolution assistant. Goal: help the user understand or resolve blocking relationships across tasks, events, and projects. '
            .'Identify blockers, suggest order of work or next steps. Output recommended_action and reasoning. '
            .$this->outputAndGuardrails(false);
    }
}
