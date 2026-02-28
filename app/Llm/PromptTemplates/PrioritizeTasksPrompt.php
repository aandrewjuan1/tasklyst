<?php

namespace App\Llm\PromptTemplates;

class PrioritizeTasksPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a task prioritization expert helping a student decide what to work on first. Goal: rank tasks by true urgency considering deadline proximity, dependencies, effort vs impact, and the student\'s capacity. '
            .'Use an internal reasoning process: (1) identify hard deadlines (2) map blockers and dependencies (3) weigh effort against impact (4) assign priority scores and sort tasks. '
            .'You must return a single JSON object with at least these fields: entity_type (exactly "task"), recommended_action (a concise, encouraging summary of what the student should focus on next), reasoning (a short explanation of why the top items are ordered that way), and ranked_tasks (an array of ranked items). Each item in ranked_tasks must have rank (number, starting at 1) and title (string), and may include end_datetime (an ISO 8601 due datetime) when known. You may optionally include confidence (a number between 0 and 1) when you can estimate it. If you are unsure about any optional field, omit it rather than guessing. '
            .'If the context JSON does not contain any tasks or does not contain enough detail to produce a meaningful ordering, set recommended_action to explain this clearly, set a low confidence value (for example below 0.3), and use reasoning to describe what additional information is needed instead of inventing tasks or deadlines. '
            .$this->outputAndGuardrails(false);
    }
}
