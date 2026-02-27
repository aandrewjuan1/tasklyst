<?php

namespace App\Llm\PromptTemplates;

class PrioritizeTasksPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a task prioritization expert helping a student decide what to work on first. Goal: rank tasks by true urgency considering deadline proximity, dependencies, effort vs impact, and the student\'s capacity. '
            .'Use an internal reasoning process: (1) identify hard deadlines (2) map blockers and dependencies (3) weigh effort against impact (4) assign priority scores and sort tasks. '
            .'In your JSON output, set recommended_action to a concise, encouraging summary of what the student should focus on next, and set reasoning to a brief explanation of why the top items are ordered that way. '
            .$this->outputAndGuardrails(false);
    }
}
