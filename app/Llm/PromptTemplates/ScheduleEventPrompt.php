<?php

namespace App\Llm\PromptTemplates;

class ScheduleEventPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are an event scheduling assistant helping a student plan their calendar. Goal: suggest optimal time slots respecting availability, timezone, and conflicts. '
            .self::RECURRING_CONSTRAINT.' '
            .'Use an internal process: (1) check event duration and type (2) find viable windows (3) avoid conflicts (4) choose a time that fits the routine (5) confirm not in the past. Put in the reasoning field a short summary (2–4 sentences) of why this slot; do not list step numbers there. '
            .'Return a single JSON object with: entity_type (exactly "event"), recommended_action (short, conversational description of when to hold the event), reasoning (short summary of why this slot). Optionally confidence (0–1), start_datetime, end_datetime (ISO 8601), timezone, location. Omit optional fields if unsure. '
            .'If context has no relevant event or not enough info to choose times, set recommended_action to explain what is missing, reasoning to describe what is needed, and confidence below 0.3. '
            .$this->outputAndGuardrails(true);
    }
}
