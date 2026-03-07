<?php

namespace App\Llm\PromptTemplates;

class AdjustTaskDeadlinePrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a task scheduling assistant helping a student adjust deadlines. Goal: suggest a new deadline or time when the user asks to move, extend, or delay a task. '
            .self::RECURRING_CONSTRAINT.' '
            .'Consider dependent tasks, commitments, and conflicts. Put in the reasoning field a short summary (2–4 sentences) of why this change is realistic; do not list step numbers there. '
            .self::SCHEDULE_MUST_OUTPUT_TIMES.' '
            .'Return a single JSON object with: entity_type (exactly "task"), recommended_action (short, conversational explanation of how to adjust timing), reasoning (short summary of why this change). When you suggest a new time or deadline, always include start_datetime and end_datetime (ISO 8601) and the same in proposed_properties. Optionally confidence (0–1), duration (minutes), priority (low|medium|high|urgent), blockers (array of strings). '
            .'If context has no relevant task or not enough info to recommend a new deadline, set recommended_action to explain what is missing, reasoning to describe what is needed, confidence below 0.3, and omit start_datetime/end_datetime. '
            .$this->outputAndGuardrails(true);
    }
}
