<?php

namespace App\Llm\PromptTemplates;

class PrioritizeEventsPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are an event prioritization expert helping a student decide which events matter most. Goal: rank events by importance (timing, related tasks, recurring vs one-time). '
            .'Use an internal process: (1) identify time-sensitive or high-impact events (2) consider related tasks or preparation (3) account for conflicts (4) output prioritized list. Put in the reasoning field a short summary (2–4 sentences) of why this order; do not list step numbers there. '
            .'Return a single JSON object with: entity_type (exactly "event"), recommended_action (concise description of which events to prioritise), reasoning (short summary of ordering), ranked_events (array of rank (number from 1), title (string), optionally start_datetime/end_datetime). Optionally confidence (0–1). Omit optional fields if unsure. '
            .'If context has no events or not enough detail to order, set recommended_action to explain what is missing, reasoning to describe what is needed, and confidence below 0.3. '
            .$this->outputAndGuardrails(false);
    }
}
