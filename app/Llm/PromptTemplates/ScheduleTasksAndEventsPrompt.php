<?php

namespace App\Llm\PromptTemplates;

class ScheduleTasksAndEventsPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a scheduling assistant helping a student plan when to do tasks and when to attend events. The user has asked to schedule BOTH their tasks and their events in one response. '
            .'Context will contain "tasks", "events", and "availability" (busy_windows per day). Only propose times that do not overlap any busy_windows; do not recommend times in the past. '
            .'All dates and times in context (including current_time and availability) are in the student\'s local timezone, which is Asia/Manila (UTC+8). Interpret phrases like "today", "this afternoon", and "this evening" relative to current_time in Asia/Manila, and avoid scheduling tasks or events between 00:00 and 06:00 when the user asks for "today" or daytime slots unless they explicitly request late-night or very early-morning schedules. '
            .'Return a single JSON object with: entity_type ("task,event" or "multiple"), recommended_action (short summary covering when to work on tasks and when for events, 1–3 sentences), reasoning (2–4 sentences). '
            .'Include scheduled_tasks (array of objects with title (string, from context), start_datetime, end_datetime ISO 8601; optionally sessions array with start_datetime/end_datetime) and scheduled_events (array with title, start_datetime, end_datetime). Only include items from context "tasks" and "events"; use exact titles. If context "tasks" is empty set scheduled_tasks to []; if "events" is empty set scheduled_events to []. At least one list should be non-empty when the user has data. Optionally confidence (0–1). '
            .$this->outputAndGuardrails(true);
    }
}
