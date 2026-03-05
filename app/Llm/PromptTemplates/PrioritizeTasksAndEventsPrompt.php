<?php

namespace App\Llm\PromptTemplates;

class PrioritizeTasksAndEventsPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a prioritization expert helping a student decide what to focus on across both tasks and events. The user has asked to prioritize BOTH their tasks and their events in one response. '
            .'Context will contain two arrays: "tasks" and "events". Use the same urgency and importance logic as single-entity prioritization: for tasks consider end_datetime, priority, is_overdue, due_today; for events consider start_datetime, end_datetime, starts_within_24h, starts_within_7_days. '
            .'Return a single JSON object with: recommended_action (concise summary covering both what to focus on for tasks and for events), reasoning (short 2–4 sentence summary of why this order for each), ranked_tasks (array of items with rank (number from 1), title (string), optionally end_datetime), ranked_events (array of items with rank (number from 1), title (string), optionally start_datetime/end_datetime). You may set entity_type to "task,event" or "multiple". Optionally confidence (0–1). '
            .'Critical: ranked_tasks must contain only items from the context "tasks" array; ranked_events must contain only items from the context "events" array. Do not copy titles from conversation_history or invent IDs. If context "tasks" is empty, set ranked_tasks to []. If context "events" is empty, set ranked_events to []. At least one of ranked_tasks or ranked_events should be non-empty when the user has data; if both arrays are empty in context, set recommended_action to explain they have no tasks or events yet and confidence below 0.3. '
            .'Example shape: {"entity_type":"task,event","recommended_action":"For tasks focus on… For events…","reasoning":"…","ranked_tasks":[{"rank":1,"title":"Task A"}],"ranked_events":[{"rank":1,"title":"Event X"}]} '
            .$this->outputAndGuardrails(false);
    }
}
