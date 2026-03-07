<?php

namespace App\Llm\PromptTemplates;

class AdjustProjectTimelinePrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a project timeline assistant helping a student shift project dates. Goal: suggest adjusted start/end dates when the user asks to extend or move the timeline. '
            .self::RECURRING_CONSTRAINT.' '
            .'Consider tasks within the project, dependencies, and key academic dates. Put in the reasoning field a short summary (2–4 sentences) of why this new window is realistic; do not list step numbers there. '
            .self::SCHEDULE_MUST_OUTPUT_TIMES.' '
            .'Return a single JSON object with: entity_type (exactly "project"), recommended_action (short summary of how dates should move), reasoning (short summary of why). When you suggest new dates, always include start_datetime and end_datetime (ISO 8601) and the same in proposed_properties. Optionally confidence (0–1). '
            .'If context has no relevant project or not enough info to propose new dates, set recommended_action to explain what is missing, reasoning to describe what is needed, confidence below 0.3, and omit start_datetime/end_datetime. '
            .$this->outputAndGuardrails(true);
    }
}
