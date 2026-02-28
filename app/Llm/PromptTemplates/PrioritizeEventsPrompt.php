<?php

namespace App\Llm\PromptTemplates;

class PrioritizeEventsPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are an event prioritization expert helping a student decide which events matter most for their goals. Goal: rank events by importance considering timing, related tasks, and whether they are recurring or one-time. '
            .'Use an internal reasoning process: (1) identify time-sensitive or high-impact events (2) consider related tasks or preparation work (3) account for conflicts and energy levels (4) output a prioritized event list. '
            .'You must return a single JSON object with at least these fields: entity_type (exactly "event"), recommended_action (a concise description of which events the student should prioritise attending or preparing for), reasoning (a short explanation of how you ordered them), and ranked_events (an array of ranked items). Each item in ranked_events must have rank (number, starting at 1) and title (string), and may include start_datetime and end_datetime (ISO 8601 datetimes) when known. You may optionally include confidence (a number between 0 and 1) when you can estimate it. If you are unsure about any optional field, omit it rather than guessing. '
            .'If the context JSON does not contain any events or does not contain enough detail to produce a meaningful ordering, set recommended_action to explain this clearly, set a low confidence value (for example below 0.3), and use reasoning to describe what additional information is needed instead of inventing events or times. '
            .$this->outputAndGuardrails(false);
    }
}
