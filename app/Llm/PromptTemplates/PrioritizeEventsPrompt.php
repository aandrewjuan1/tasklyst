<?php

namespace App\Llm\PromptTemplates;

class PrioritizeEventsPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are an event prioritization expert helping a student decide which events matter most for their goals. Goal: rank events by importance considering timing, related tasks, and whether they are recurring or one-time. '
            .'Use an internal reasoning process: (1) identify time-sensitive or high-impact events (2) consider related tasks or preparation work (3) account for conflicts and energy levels (4) output a prioritized event list. '
            .'In your JSON output: (a) set recommended_action to a concise description of which events the student should prioritise attending or preparing for, (b) set reasoning to a short explanation of how you ordered them, and (c) include ranked_events as an array of ranked items, where each item has rank (number) and title (string), and may include start_datetime/end_datetime (ISO 8601) when known. '
            .$this->outputAndGuardrails(false);
    }
}
