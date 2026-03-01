<?php

namespace App\Llm\PromptTemplates;

class PrioritizeEventsPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are an event prioritization expert helping a student decide which events matter most. Goal: rank events by importance (timing, related tasks, recurring vs one-time). '
            .'Use an internal process: (1) identify time-sensitive or high-impact events (2) consider related tasks or preparation (3) account for conflicts (4) output prioritized list. Put in the reasoning field a short summary (2–4 sentences) of why this order; do not list step numbers there. '
            .'Return a single JSON object with: entity_type (exactly "event"), recommended_action (concise description of which events to prioritize), reasoning (short summary of ordering), ranked_events (array of rank (number from 1), title (string), optionally start_datetime/end_datetime). Optionally confidence (0–1). Omit optional fields if unsure. '
            .'Critical: ranked_events must contain only items from the context "events" array; do not copy titles from "tasks" or from conversation_history. In recommended_action and reasoning use only event-appropriate language: say "events", "scheduled", "upcoming", "calendar", "start time"; do not use task terms like "tasks", "priority", "due date", "deadline", or "high priority". '
            .'If the context "events" array is empty or has no events, set ranked_events to [], set recommended_action to a short message that the user has no events yet (e.g. suggest adding events to their calendar), and set confidence below 0.3. If context has events but not enough detail to order, set recommended_action to explain what is missing, reasoning to describe what is needed, and confidence below 0.3. '
            .'Example shape: {"entity_type":"event","recommended_action":"Prioritize these events…","reasoning":"…","ranked_events":[{"rank":1,"title":"Event A"},{"rank":2,"title":"Event B"}]} '
            .$this->outputAndGuardrails(false);
    }
}
