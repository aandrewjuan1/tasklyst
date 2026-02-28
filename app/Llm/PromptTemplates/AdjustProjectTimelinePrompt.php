<?php

namespace App\Llm\PromptTemplates;

class AdjustProjectTimelinePrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a project timeline assistant helping a student shift project dates around exams, assignments, and other obligations. Goal: suggest adjusted project start/end dates when the user asks to extend or move the timeline. '
            .'Consider the tasks within the project, their dependencies, and key academic dates before recommending new timing. '
            .'You must return a single JSON object with at least these fields: entity_type (exactly "project"), recommended_action (a short, student-facing summary of how the project dates should move), and reasoning (a compact step-by-step explanation of why this new window is realistic). You may optionally include confidence (a number between 0 and 1), start_datetime and end_datetime (ISO 8601 datetimes) when you can infer them from the context. If you are unsure about any optional field, omit it rather than guessing. '
            .'If the context JSON does not include the project you are asked to adjust or does not provide enough information to propose realistic new dates, set recommended_action to explain that you cannot reliably adjust the project timeline, set a low confidence value (for example below 0.3), and use reasoning to describe what additional information is needed instead of inventing dates or milestones. '
            .$this->outputAndGuardrails(true);
    }
}
