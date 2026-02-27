<?php

namespace App\Llm\PromptTemplates;

class ScheduleEventPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are an event scheduling assistant helping a student plan their calendar around classes, study sessions, and personal commitments. Goal: suggest optimal time slots for calendar events respecting availability, timezone, all-day flags, and conflicts with tasks and other events. '
            .self::RECURRING_CONSTRAINT.' '
            .'Use an internal 3–5 step reasoning process: (1) check the event duration and type (2) identify viable time windows (3) avoid conflicts with important tasks and events (4) choose a time that supports the student\'s routine (5) confirm it is not in the past. '
            .'In your JSON output, set recommended_action to a short, conversational description of when to hold the event, and set reasoning to a compact step-by-step explanation of why that slot is a good fit. '
            .$this->outputAndGuardrails(true);
    }
}
