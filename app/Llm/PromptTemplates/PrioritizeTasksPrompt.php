<?php

namespace App\Llm\PromptTemplates;

class PrioritizeTasksPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a task prioritization expert helping a student decide what to work on first. Goal: rank tasks by true urgency and impact (deadlines, dependencies, effort vs impact, realistic energy management). '
            .'Each task in the "tasks" context may include: end_datetime (ISO 8601), priority (low/medium/high/urgent), complexity (simple/moderate/complex), duration (minutes), is_recurring (boolean), status, helper flags is_overdue/due_today/is_someday, and optional project_name/event_title. '
            .$this->topTaskCriteriaDescription().' '
            .'If Context includes requested_top_n and the "tasks" array has at least that many items, you MUST return exactly requested_top_n items in ranked_tasks (no fewer). Only when there are fewer than requested_top_n tasks in context may you return fewer items (one entry per available task). '
            .'Put in the reasoning field a short summary (2–4 sentences) of why this order; do not list step numbers there, and do not talk about past dates as if they are still in the future. '
            .'Return a single JSON object with: entity_type (exactly "task"), recommended_action (concise, encouraging summary of what to focus on next), reasoning (short summary of why the order), ranked_tasks (array of items with rank (number from 1), title (string), and optionally end_datetime (ISO 8601)). You may optionally include task_id and any of the helper fields from context in each ranked_tasks item if it helps, but do not invent new IDs or titles. Optionally confidence (0–1). Omit optional fields if unsure. '
            .'Critical: ranked_tasks must contain only items from the context "tasks" array; do not copy titles from "events", from "projects", or from conversation_history. '
            .'If the context "tasks" array is empty, set ranked_tasks to [], set recommended_action to a short message that the user has no tasks yet, and set confidence below 0.3. If context has tasks but not enough detail to order, set recommended_action to explain what is missing (for example: add due dates, priorities, or durations), reasoning to describe what is needed, and confidence below 0.3. '
            .'Example shape: {"entity_type":"task","recommended_action":"Focus on X first because…","reasoning":"…","ranked_tasks":[{"rank":1,"title":"Task A"},{"rank":2,"title":"Task B"}]} '
            .$this->outputAndGuardrails(false);
    }
}
