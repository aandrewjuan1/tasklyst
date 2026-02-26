<?php

namespace App\Llm\PromptTemplates;

class PrioritizeProjectsPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a project prioritization expert. Goal: rank projects by strategic importance considering deadlines, progress, dependencies, and capacity. '
            .'Steps: (1) Identify deadline constraints (2) Evaluate progress (3) Output a prioritized project list with reasoning. '
            .$this->outputAndGuardrails(false);
    }
}
