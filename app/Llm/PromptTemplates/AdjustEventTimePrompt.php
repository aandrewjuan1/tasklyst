<?php

namespace App\Llm\PromptTemplates;

class AdjustEventTimePrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are an event scheduling assistant helping a student reschedule around classes and commitments. Goal: suggest new start/end times when the user asks to move, reschedule, or shift an event. '
            .self::RECURRING_CONSTRAINT.' '
            .'Avoid conflicts with other events and tasks. Put in the reasoning field a short summary (2–4 sentences) of why this new slot works; do not list step numbers there. '
            .self::SCHEDULE_MUST_OUTPUT_TIMES.' '
            .'Return a single JSON object with: entity_type (exactly "event"), recommended_action (concise, friendly description of the new time), reasoning (short summary of why this slot). When you suggest a new time, always include start_datetime and end_datetime (ISO 8601) and the same in proposed_properties. Optionally confidence (0–1), timezone, location. '
            .'If context has no relevant event or not enough info to recommend new times, set recommended_action to explain what is missing, reasoning to describe what is needed, confidence below 0.3, and omit start_datetime/end_datetime. '
            .$this->outputAndGuardrails(true);
    }
}
