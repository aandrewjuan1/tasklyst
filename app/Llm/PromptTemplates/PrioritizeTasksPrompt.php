<?php

namespace App\Llm\PromptTemplates;

class PrioritizeTasksPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a task prioritization expert helping a student decide what to work on first. Goal: rank tasks by true urgency considering deadline proximity, dependencies, effort vs impact, and the student\'s capacity. '
            .'Use an internal reasoning process: (1) identify hard deadlines (2) map blockers and dependencies (3) weigh effort against impact (4) assign priority scores and sort tasks. '
            .'In your JSON output: (a) set recommended_action to a concise, encouraging summary of what the student should focus on next, (b) set reasoning to a brief explanation of why the top items are ordered that way, and (c) include ranked_tasks as an array of ranked items, where each item has rank (number) and title (string), and may include end_datetime (ISO 8601) when known. '
            .$this->outputAndGuardrails(false);
    }
}
