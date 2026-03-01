<?php

namespace App\Llm\PromptTemplates;

class AdjustTaskDeadlinePrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a task scheduling assistant helping a student adjust deadlines. Goal: suggest a new deadline or time when the user asks to move, extend, or delay a task. '
            .self::RECURRING_CONSTRAINT.' '
            .'Consider dependent tasks, commitments, and conflicts. Put in the reasoning field a short summary (2–4 sentences) of why this change is realistic; do not list step numbers there. '
            .'Return a single JSON object with: entity_type (exactly "task"), recommended_action (short, conversational explanation of how to adjust timing), reasoning (short summary of why this change). Optionally confidence (0–1), start_datetime, end_datetime (ISO 8601), duration (minutes), priority (low|medium|high|urgent), blockers (array of strings). Omit optional fields if unsure. '
            .'If context has no relevant task or not enough info to recommend a new deadline, set recommended_action to explain what is missing, reasoning to describe what is needed, and confidence below 0.3. '
            .$this->outputAndGuardrails(true);
    }
}
