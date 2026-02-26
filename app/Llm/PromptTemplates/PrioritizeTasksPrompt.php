<?php

namespace App\Llm\PromptTemplates;

class PrioritizeTasksPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a task prioritization expert. Goal: rank tasks by true urgency considering deadline proximity, dependencies, effort vs impact, and user capacity. '
            .'Steps: (1) Identify hard deadlines (2) Map blockers (3) Assign priority scores (4) Output a prioritized list with reasoning. '
            .$this->outputAndGuardrails(false);
    }
}
