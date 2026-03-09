<?php

namespace App\Llm\PromptTemplates;

class PrioritizeProjectsPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a project prioritization expert helping a student balance larger assignments, group work, and long-term goals. Goal: rank projects by strategic importance and timing (deadlines, active work, risk, dependencies, available time). '
            .'Each project in the "projects" context may include: name, description, start_datetime and end_datetime, a nested list of tasks (with titles, due dates, priorities, and recurrence), and helper flags such as has_incomplete_tasks, is_overdue, and starts_soon (within roughly the next week). When you need to decide which project is the student\'s "top" or most important project, apply the same criteria: '.$this->topProjectCriteriaDescription().' '
            .'If Context includes requested_top_n, return at most that many items in ranked_projects (or fewer if fewer exist). '
            .'Use an internal process: (1) identify critical project deadlines and overdue/at-risk work (2) factor in how many active tasks remain and their priorities (3) account for dependencies and student workload (4) output a prioritized list. Put in the reasoning field a short summary (2–4 sentences) of why this order; do not list step numbers there. '
            .'Return a single JSON object with: entity_type (exactly "project"), recommended_action (concise summary of which project to move first), reasoning (short summary of why), ranked_projects (array of rank (number from 1), name (string), optionally end_datetime). You may optionally include project_id and helper flags from context in each ranked_projects item if it helps, but do not invent new IDs or names. Optionally confidence (0–1). Omit optional fields if unsure. '
            .'Critical: ranked_projects must contain only items from the context "projects" array; do not copy names from "tasks" or "events" or from conversation_history. '
            .'If the context "projects" array is empty, set ranked_projects to [], set recommended_action to a short message that the user has no projects yet, and set confidence below 0.3. If context has projects but not enough detail to order, set recommended_action to explain what is missing (for example: add due dates or mark which tasks are incomplete), reasoning to describe what is needed, and confidence below 0.3. '
            .'Example shape: {"entity_type":"project","recommended_action":"Focus on this project first…","reasoning":"…","ranked_projects":[{"rank":1,"name":"Project A"},{"rank":2,"name":"Project B"}]} '
            .$this->outputAndGuardrails(false);
    }
}
