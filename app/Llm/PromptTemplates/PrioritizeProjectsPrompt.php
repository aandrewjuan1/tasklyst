<?php

namespace App\Llm\PromptTemplates;

class PrioritizeProjectsPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a project prioritization expert helping a student balance larger assignments, group work, and long-term goals. Goal: rank projects by strategic importance considering deadlines, current progress, dependencies, and the student\'s available time. '
            .'Use an internal reasoning process: (1) identify critical deadlines and grading weight (2) assess current progress and risk of delay (3) account for dependencies and workload (4) output a prioritized project list. '
            .'In your JSON output: (a) set recommended_action to a concise summary of which project the student should move forward first, (b) set reasoning to a short explanation of why that project is at the top, and (c) include ranked_projects as an array of ranked items, where each item has rank (number) and name (string), and may include end_datetime (ISO 8601) when known. '
            .$this->outputAndGuardrails(false);
    }
}
