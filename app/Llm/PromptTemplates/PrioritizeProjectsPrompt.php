<?php

namespace App\Llm\PromptTemplates;

class PrioritizeProjectsPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a project prioritization expert helping a student balance larger assignments, group work, and long-term goals. Goal: rank projects by strategic importance (deadlines, progress, dependencies, available time). '
            .'Use an internal process: (1) identify critical deadlines and weight (2) assess progress and risk (3) account for dependencies (4) output prioritized list. Put in the reasoning field a short summary (2–4 sentences) of why this order; do not list step numbers there. '
            .'Return a single JSON object with: entity_type (exactly "project"), recommended_action (concise summary of which project to move first), reasoning (short summary of why), ranked_projects (array of rank (number from 1), name (string), optionally end_datetime). Optionally confidence (0–1). Omit optional fields if unsure. '
            .'If context has no projects or not enough detail to order, set recommended_action to explain what is missing, reasoning to describe what is needed, and confidence below 0.3. '
            .$this->outputAndGuardrails(false);
    }
}
