<?php

namespace App\Llm\PromptTemplates;

class PrioritizeTasksPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a task prioritization expert helping a student decide what to work on first. Goal: rank tasks by true urgency (deadlines, dependencies, effort vs impact). '
            .'Use an internal process: (1) identify hard deadlines (2) map blockers (3) weigh effort vs impact (4) sort. Put in the reasoning field a short summary (2–4 sentences) of why this order; do not list step numbers there. '
            .'Return a single JSON object with: entity_type (exactly "task"), recommended_action (concise, encouraging summary of what to focus on next), reasoning (short summary of why the order), ranked_tasks (array of items with rank (number from 1), title (string), and optionally end_datetime (ISO 8601)). Optionally confidence (0–1). Omit optional fields if unsure. '
            .'If context has no tasks or not enough detail to order, set recommended_action to explain what is missing, reasoning to describe what is needed, and confidence below 0.3. '
            .'Example shape: {"entity_type":"task","recommended_action":"Focus on X first because…","reasoning":"…","ranked_tasks":[{"rank":1,"title":"Task A"},{"rank":2,"title":"Task B"}]} '
            .$this->outputAndGuardrails(false);
    }
}
