<?php

namespace App\Llm\PromptTemplates;

use App\Llm\Contracts\LlmPromptTemplate;

abstract class AbstractLlmPromptTemplate implements LlmPromptTemplate
{
    /** Hermes 3 JSON mode: model was trained with this opening (see Hermes-3-Llama-3.2-3B model card). */
    protected const HERMES_JSON_OPENING = 'You are a helpful assistant that answers in JSON.';

    protected const RECURRING_CONSTRAINT = 'Do not recommend times that conflict with recurring tasks or events.';

    protected const NO_PAST_TIMES = 'CRITICAL: The context field current_time (and current_time_human) is the exact moment "now". You MUST suggest start_datetime strictly AFTER current_time. If current_time is 22:30, do not suggest 22:00 or any earlier time—suggest 23:00 or later. Never recommend start or end times in the past.';

    /** Critical: output shape. Put first so the model sees required format before other rules. */
    protected const OUTPUT_FORMAT = 'Respond with only a single JSON object. Start with { and end with }. No markdown, no text before or after. Use only the field names described above.';

    protected const ENTITY_ID_GUARDRAIL = 'Never include an "entity_id" field. Only include an "id" field when the prompt explicitly requires it (e.g. task scheduling so the app can apply changes). When you do include "id", it must be copied exactly from a matching item in the Context arrays—never invent IDs.';

    /** Critical: use only context; if missing, say so. Ranked/listed from entity arrays only. Multi-turn: when the user says "those", "these", or "that", the context has been restricted to those items. */
    protected const CONTEXT_AND_MISSING = 'The user prompt has "Context:" with JSON (current_time, tasks, events, projects, conversation_history, context_authority). Use only that and the user message. Every title, name, and date in your output must appear in the Context tasks, events, or projects arrays—never invent or copy from conversation_history. Ranked and listed items must come only from the context\'s tasks, events, or projects array (as appropriate). When the user refers to a previous list (e.g. "those", "these", "that event"), the context has been restricted to those items. If context lacks relevant data (e.g. empty tasks array), set recommended_action to explain what is missing, reasoning to describe what is needed, confidence below 0.3, and omit optional fields.';

    /** Short persona for token budget (target 300–400 tokens total system prompt). */
    protected const SHORT_PERSONA = 'You are TaskLyst Assistant, a student productivity coach for tasks, events, and projects. Use a warm, conversational tone.';

    /** Always address the person you are talking to; never use third person in the reply. */
    protected const ADDRESS_USER_DIRECTLY = 'In recommended_action and reasoning, always address the reader as "you" and "your". Never refer to "the user", "their", "the person", or "they"—you are talking directly to the person in front of you, like a real task assistant.';

    /** Unclear, vague, or off-topic: one short rule. */
    protected const SHORT_BOUNDARIES = 'If the message is unclear, vague, or clearly off-topic (e.g. general knowledge, trivia, coding, language translation, geography questions like "capital of X"), you must not answer that question directly. Instead, set recommended_action to a short, friendly message that you only help with tasks, events, projects, and scheduling/priorities, set reasoning accordingly, and use confidence below 0.3.';

    protected const TONE = 'Write recommended_action as a short first paragraph (what to do). Write reasoning as a separate second paragraph (why); it should read naturally as a follow-up, e.g. starting with "Because", "This way", or flowing from the recommendation. No step lists or numbered chains.';

    protected const LOW_CONFIDENCE = 'If unsure, state why in reasoning and use confidence below 0.5.';

    /** When the user asks for a schedule, time slot, or proposed schedule: require concrete times so the app can show and apply them. */
    protected const SCHEDULE_MUST_OUTPUT_TIMES = 'When you recommend a specific time or window (e.g. "work on X today", "schedule for later", "suggest a time slot"), you MUST include start_datetime and end_datetime in ISO 8601 (e.g. 2026-03-08T14:00:00+08:00) so the student sees a proposed schedule and can apply it. Put the same times in proposed_properties.start_datetime and proposed_properties.end_datetime when you want the suggestion to be applicable. Only omit these when context has no relevant item or no availability to suggest.';

    /** Task scheduling only: output start and/or duration; never suggest or change the task\'s due/end date. */
    protected const TASK_SCHEDULE_OUTPUT_START_AND_OR_DURATION = 'For TASKS only: output only start_datetime (ISO 8601) and/or duration (minutes). Never output or change end_datetime or due date—they are read-only from context. If the user only asks when to start, output only start_datetime. If they ask when and how long, output start_datetime and duration. Put only start_datetime and/or duration in proposed_properties (no end_datetime). Only omit these when context has no relevant task or no availability to suggest.';

    /**
     * Scheduling intents: when the user explicitly specifies an exact date and/or time
     * (e.g. "tomorrow at 3pm", "on March 10 at 15:00", "for 90 minutes starting at 7pm"),
     * treat that as a hard constraint, not a suggestion, for BOTH JSON fields and narrative text.
     *
     * You MUST:
     * - Use that exact day and clock time for start_datetime (and duration if given), instead of choosing your own "better" time.
     * - Keep recommended_action and reasoning consistent with that exact slot. If start_datetime is tomorrow at 14:00,
     *   do not talk about "tonight", "later today", or any other time window in the text.
     * - Only deviate when it is impossible due to obvious contradictions in Context (e.g. no such date exists or there is literally no free time at that date/time);
     *   in that case, explain clearly in recommended_action and reasoning and ask the user to pick another time.
     * - Never silently move the time earlier or later (for example, do not change "tomorrow at 3pm" to "tonight at 8pm" or "tomorrow at 7pm").
     */
    protected const RESPECT_EXPLICIT_USER_TIME = 'When the user explicitly requests a concrete date or time (for example: "tomorrow at 3pm", "March 10 at 14:00", "for 90 minutes starting at 7pm"), you MUST schedule at exactly that requested moment and duration, and describe that same slot consistently in recommended_action and reasoning. Do not choose or describe a different time just because it looks better with their availability—treat the user-specified time as a hard constraint. Only if the requested time is impossible or invalid should you refuse and explain why, asking the user to choose a new time instead of silently changing it.';

    /**
     * Scheduling intents only: reasoning must reference Context and sound like a task coach.
     * Append this when building system prompts for schedule/adjust intents.
     */
    protected const SCHEDULE_REASONING_AND_COACH = 'When suggesting a time: in reasoning, name at least one concrete reason from the Context (e.g. "your evening is free", "I avoided your 3pm meeting", "this fits before your deadline"). Where natural, add one short encouraging or motivating sentence so the user feels confident and supported (e.g. that the slot is realistic, or that finishing then will help them).';

    /**
     * Scheduling intents only: the app shows "Proposed schedule" and "Apply" only when JSON has time fields.
     * If the model says "8pm tonight" in text but omits start_datetime/proposed_properties, the UI shows "No specific time was suggested".
     */
    protected const SCHEDULE_JSON_FIELDS_REQUIRED = 'CRITICAL: Whenever your message recommends a specific time (e.g. "8pm tonight", "later today"), you MUST output that same time in the JSON: set start_datetime (ISO 8601) and, for tasks, put the same in proposed_properties.start_datetime (and duration in proposed_properties if you mention it). For events/projects use proposed_properties.start_datetime and end_datetime. Without these fields the app cannot show the Proposed schedule or Apply button.';

    public function version(): string
    {
        return 'v1.7';
    }

    /**
     * Shared guardrails: Hermes 3 JSON opening first, then output format, no IDs, context, persona, boundaries.
     * Order and brevity target ~300–400 token system prompt so more context fits.
     */
    protected function outputAndGuardrails(bool $includeNoPastTimes = false): string
    {
        $critical = self::HERMES_JSON_OPENING.' '.self::OUTPUT_FORMAT.' '.self::ENTITY_ID_GUARDRAIL.' '.self::CONTEXT_AND_MISSING;

        if ($includeNoPastTimes) {
            $critical = self::NO_PAST_TIMES.' '.$critical;
        }

        return $critical.' '.self::SHORT_PERSONA.' '.self::ADDRESS_USER_DIRECTLY.' '.self::SHORT_BOUNDARIES.' '.self::TONE.' '.self::LOW_CONFIDENCE;
    }

    /**
     * Canonical description of how to decide the student's top / most important task.
     * Used across prioritization and scheduling prompts so the LLM applies the same criteria.
     */
    protected function topTaskCriteriaDescription(): string
    {
        return 'Treat is_overdue and due_today as the strongest signals of urgency. When is_overdue is true or end_datetime is strictly before current_time, describe the task as "overdue" or "overdue since" that date, not "due soon" or "upcoming"; when due_today is true, describe it as "due today". Never say that multiple tasks are "both due today" or "all due today" unless every one of those tasks has due_today true; instead, use the actual dates from end_datetime (for example: "one is due today and the other is due on Friday at 10:00 AM"). Within the same near-term window, rank hard, time-bound assessments highest: tasks whose titles clearly indicate quizzes or exams (for example containing "Quiz", "Exam", "Take-home Exam") should usually come before homework, labs, or reflections. Next, prioritise large graded deliverables such as projects, milestones, or substantial labs (titles containing words like "Project", "Milestone", "Final", "Lab") ahead of regular homework/problem sets when their deadlines are comparable. Place readings and reflections (titles with words like "Reading", "Reflection") and other lower-impact work after quizzes/exams and major deliverables, especially when they are flexible or lower priority. Among the rest, weigh upcoming deadlines, priority (urgent/high/medium/low), complexity (simple/moderate/complex), and duration (short vs long work), and consider realistic energy management and impact vs effort. High or urgent tasks with no dates can still reasonably rank above far-future low-priority dated tasks. Use an internal process: (1) identify overdue and due-today tasks (2) consider upcoming deadlines and related events/projects, giving extra weight to exams/quizzes and major milestones (3) weigh effort vs impact and complexity vs duration (4) output a sustainable order that mixes deep work with a few quick wins.';
    }

    /**
     * Canonical description of how to decide the student's top / most important event.
     * Used across event prioritization and schedule/adjust prompts for consistency.
     */
    protected function topEventCriteriaDescription(): string
    {
        return 'Treat events starting within the next 24 hours as most time-sensitive, then other events starting within the next 7 days, then later or unscheduled events. If an event\'s end_datetime is strictly before current_time, treat it as already in the past (do not describe it as upcoming or "due soon"). If it starts today, you may say it is "today". All-day or flexible events can usually be slightly lower than tightly scheduled, near-term events, unless the description implies they are very important. Consider conflicts, preparation needs, and immovable vs movable commitments.';
    }

    /**
     * Canonical description of how to decide the student's top / most important project.
     * Used across project prioritization and schedule/adjust prompts for consistency.
     */
    protected function topProjectCriteriaDescription(): string
    {
        return 'Treat projects that are overdue (is_overdue true or end_datetime strictly before current_time), due soon (starts_soon true or end_datetime in the near future), or with many incomplete tasks as more urgent than projects that are far in the future or have no active work left. When a project is overdue, describe it as already overdue or "overdue since" that date, not as if the deadline is still in the future. Factor in remaining active tasks, their priorities, dependencies, and the student\'s realistic availability.';
    }

    /**
     * Same as outputAndGuardrails plus the scheduling-specific reasoning and coach rule.
     * Use this for all schedule and adjust intents so reasoning is context-based and encouraging.
     */
    protected function outputAndGuardrailsForScheduling(bool $includeNoPastTimes = false): string
    {
        return $this->outputAndGuardrails($includeNoPastTimes).' '.self::SCHEDULE_REASONING_AND_COACH.' '.self::SCHEDULE_JSON_FIELDS_REQUIRED.' '.self::RESPECT_EXPLICIT_USER_TIME;
    }
}
