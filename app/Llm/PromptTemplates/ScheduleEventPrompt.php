<?php

namespace App\Llm\PromptTemplates;

class ScheduleEventPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are an event scheduling assistant for a student. Suggest when to hold an event so it fits their calendar. '
            .'Output a single JSON object. Required: entity_type ("event"), recommended_action (1–3 sentences, when to hold it), reasoning (2–3 sentences, why this slot). When you suggest a time: include start_datetime and end_datetime (ISO 8601) and the same in proposed_properties. '
            .'Time rules: Context gives current_time ("now") and current_date ("today"). Suggest start_datetime strictly after current_time. For "today", "this evening", "after lunch" use current_date; for "tomorrow" use the next date. Timezone is Asia/Manila (UTC+8). Avoid 00:00–06:00 unless the user asks for late-night or early-morning. '
            .'Availability: Context has "availability" (per-date busy_windows) and "availability_meaning". Choose times only in gaps between busy_windows or on free days. Do not overlap existing events or time-blocked tasks. '
            .self::RECURRING_CONSTRAINT.' '
            .'Thinking: Check event duration, find viable gaps in availability, pick a time that fits. In reasoning, briefly explain why this slot (no step numbers). '
            .self::SCHEDULE_MUST_OUTPUT_TIMES.' '
            .$this->outputAndGuardrails(true);
    }
}
