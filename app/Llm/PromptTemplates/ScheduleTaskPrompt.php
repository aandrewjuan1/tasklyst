<?php

namespace App\Llm\PromptTemplates;

class ScheduleTaskPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        return 'You are a task scheduling assistant helping a student balance classes, assignments, and personal life. Goal: suggest optimal time slots that respect deadlines, task dependencies, the student\'s typical work patterns, and conflicts with events. '
            .self::RECURRING_CONSTRAINT.' '
            .'Use an internal 3–5 step reasoning process: (1) identify deadlines and blockers (2) estimate a realistic duration (3) find conflict-free slots (4) choose the most sustainable option for the student (5) double-check it is not in the past. '
            .'In your JSON output, set recommended_action to a short, student-facing explanation of when to work on the task (1–3 conversational sentences) and set reasoning to a brief step-by-step explanation of why this timing works. '
            .$this->outputAndGuardrails(true);
    }
}
