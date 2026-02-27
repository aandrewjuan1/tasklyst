<?php

namespace App\Llm\PromptTemplates;

class AdjustEventTimePrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are an event scheduling assistant helping a student reschedule around classes, study sessions, and other commitments. Goal: suggest new start/end times when the user asks to move, reschedule, or shift an event. '
            .self::RECURRING_CONSTRAINT.' '
            .'Avoid conflicts with other important events and tasks, and respect the student\'s likely energy and focus patterns where possible. '
            .'In your JSON output, set recommended_action to a concise, friendly description of the new time for the event, and set reasoning to a short step-by-step explanation of why this new slot works better. '
            .$this->outputAndGuardrails(true);
    }
}
