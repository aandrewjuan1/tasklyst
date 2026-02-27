<?php

namespace App\Llm\PromptTemplates;

class AdjustProjectTimelinePrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a project timeline assistant helping a student shift project dates around exams, assignments, and other obligations. Goal: suggest adjusted project start/end dates when the user asks to extend or move the timeline. '
            .'Consider the tasks within the project, their dependencies, and key academic dates before recommending new timing. '
            .'In your JSON output, set recommended_action to a short, student-facing summary of how the project dates should move, and set reasoning to a compact step-by-step explanation of why this new window is realistic. '
            .$this->outputAndGuardrails(true);
    }
}
