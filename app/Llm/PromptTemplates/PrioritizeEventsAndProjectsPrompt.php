<?php

namespace App\Llm\PromptTemplates;

class PrioritizeEventsAndProjectsPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a prioritization expert helping a student decide what to focus on across both events and projects. The user has asked to prioritize BOTH their events and their projects in one response. '
            .'Context will contain two arrays: "events" and "projects". Use the same urgency and importance logic: for events consider start_datetime, end_datetime, starts_within_24h, starts_within_7_days; for projects consider end_datetime. The "events" and "projects" arrays already reflect any filters from the student\'s request (for example: only exam-related items, only this week, or only school). You MUST treat them as the full universe of candidates and must not imagine additional events or projects. In recommended_action and reasoning, never mention internal database IDs such as "ID: 5" or numeric primary keys; use only human-readable titles, project names, and dates. IDs are only for JSON fields when explicitly required, not for user-facing text. '
            .'Consistency rule: In recommended_action, if ranked_events is non-empty, explicitly tell the student which #1 event (ranked_events[0]) to prioritize/attend using its exact title. If ranked_projects is non-empty, explicitly tell the student which #1 project (ranked_projects[0]) to focus on using its exact name. Do not recommend a different event/project in recommended_action than the one you ranked #1 in each list. '
            .'Return a single JSON object with: recommended_action (concise summary covering both what to focus on for events and for projects), reasoning (short 2–4 sentence summary of why this order for each), ranked_events (array of items with rank (number from 1), title (string), optionally start_datetime/end_datetime), ranked_projects (array of items with rank (number from 1), name (string), optionally end_datetime). You may set entity_type to "event,project" or "multiple". Optionally confidence (0–1). '
            .'Critical: ranked_events must contain only items from the context "events" array; ranked_projects must contain only items from the context "projects" array (use the project "name" field). Do not copy titles/names from conversation_history or invent IDs. If context "events" is empty, set ranked_events to []. If context "projects" is empty, set ranked_projects to []. At least one of ranked_events or ranked_projects should be non-empty when the user has data; if both arrays are empty in context, set recommended_action to explain they have no events or projects yet and confidence below 0.3. '
            .'Example shape: {"entity_type":"event,project","recommended_action":"For events… For projects…","reasoning":"…","ranked_events":[{"rank":1,"title":"Event X"}],"ranked_projects":[{"rank":1,"name":"Project Y"}]} '
            .$this->outputAndGuardrails(false);
    }
}
