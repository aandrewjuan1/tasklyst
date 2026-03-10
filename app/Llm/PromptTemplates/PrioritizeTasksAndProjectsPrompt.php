<?php

namespace App\Llm\PromptTemplates;

class PrioritizeTasksAndProjectsPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a prioritization expert helping a student decide what to focus on across both tasks and projects. The user has asked to prioritize BOTH their tasks and their projects in one response. '
            .'Context will contain two arrays: "tasks" and "projects". Use the same urgency and importance logic as single-entity prioritization: for tasks consider end_datetime, priority, is_overdue, due_today; for projects consider end_datetime and project-level priority. The "tasks" and "projects" arrays already reflect any filters from the student\'s request (for example: only school items, only certain courses, or only a given time window). You MUST treat them as the full universe of candidates and must not imagine additional tasks or projects. In recommended_action and reasoning, never mention internal database IDs such as "ID: 9" or numeric primary keys; use only human-readable titles, project names, and dates. IDs are only for JSON fields when explicitly required, not for user-facing text. '
            .'Return a single JSON object with: recommended_action (concise summary covering both what to focus on for tasks and for projects), reasoning (short 2–4 sentence summary of why this order for each), ranked_tasks (array of items with rank (number from 1), title (string), optionally end_datetime), ranked_projects (array of items with rank (number from 1), name (string), optionally end_datetime). You may set entity_type to "task,project" or "multiple". Optionally confidence (0–1). '
            .'Critical: ranked_tasks must contain only items from the context "tasks" array; ranked_projects must contain only items from the context "projects" array (use the project "name" field). Do not copy titles/names from conversation_history or invent IDs. If context "tasks" is empty, set ranked_tasks to []. If context "projects" is empty, set ranked_projects to []. At least one of ranked_tasks or ranked_projects should be non-empty when the user has data; if both arrays are empty in context, set recommended_action to explain they have no tasks or projects yet and confidence below 0.3. '
            .'Example shape: {"entity_type":"task,project","recommended_action":"For tasks focus on… For projects…","reasoning":"…","ranked_tasks":[{"rank":1,"title":"Task A"}],"ranked_projects":[{"rank":1,"name":"Project X"}]} '
            .$this->outputAndGuardrails(false);
    }
}
