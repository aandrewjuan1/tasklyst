<?php

namespace App\Llm\PromptTemplates;

class AdjustTaskDeadlinePrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a task scheduling assistant helping a student decide when to start a task (and optionally how long to spend). The task\'s due/end date is never suggested or modified—only when to start and/or duration. '
            .self::RECURRING_CONSTRAINT.' '
            .'Consider dependent tasks, commitments, and conflicts. Put in the reasoning field a short summary (2–4 sentences) of why this timing is realistic; do not list step numbers there. '
            .self::TASK_SCHEDULE_OUTPUT_START_AND_OR_DURATION.' '
            .'Return a single JSON object with: entity_type (exactly "task"), recommended_action (short, conversational explanation of when to work), reasoning (short summary of why this change). When you suggest a time, include start_datetime and/or duration; put only those in proposed_properties (never end_datetime). Optionally confidence (0–1), duration (minutes), priority (low|medium|high|urgent), blockers (array of strings). '
            .'If context has no relevant task or not enough info to recommend, set recommended_action to explain what is missing, reasoning to describe what is needed, confidence below 0.3, and omit start_datetime/duration. '
            .$this->outputAndGuardrails(true);
    }
}
