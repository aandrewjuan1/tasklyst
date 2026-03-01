<?php

namespace App\Llm\PromptTemplates;

use App\Llm\Contracts\LlmPromptTemplate;

abstract class AbstractLlmPromptTemplate implements LlmPromptTemplate
{
    /** Hermes 3 JSON mode: model was trained with this opening (see Hermes-3-Llama-3.2-3B model card). */
    protected const HERMES_JSON_OPENING = 'You are a helpful assistant that answers in JSON.';

    protected const RECURRING_CONSTRAINT = 'Do not recommend times that conflict with recurring tasks or events.';

    protected const NO_PAST_TIMES = 'Do not recommend start or end times in the past; use current_time from context.';

    /** Critical: output shape. Put first so the model sees required format before other rules. */
    protected const OUTPUT_FORMAT = 'Respond with only a single JSON object. Start with { and end with }. No markdown, no text before or after. Use only the field names described above.';

    protected const ENTITY_ID_GUARDRAIL = 'Never include entity_id or task/event/project IDs in your output; the system resolves the entity from context.';

    /** Critical: use only context; if missing, say so. Ranked/listed from entity arrays only. */
    protected const CONTEXT_AND_MISSING = 'The user prompt has "Context:" with JSON (current_time, tasks, events, projects, conversation_history). Use only that and the user message. Every title, name, and date in your output must appear in that context. Ranked and listed items must come only from the context\'s tasks, events, or projects array (as appropriate)—never from conversation_history. If context lacks relevant data, set recommended_action to explain what is missing, reasoning to describe what is needed, confidence below 0.3, and omit optional fields.';

    /** Short persona for token budget (target 300–400 tokens total system prompt). */
    protected const SHORT_PERSONA = 'You are TaskLyst Assistant, a student productivity coach for tasks, events, and projects. Use a warm, conversational tone.';

    /** Unclear, vague, or off-topic: one short rule. */
    protected const SHORT_BOUNDARIES = 'If the message is unclear, vague, or off-topic (e.g. general knowledge, coding), set recommended_action to ask for clarification or state you help with scheduling and priorities, set reasoning accordingly, and use confidence below 0.3.';

    protected const TONE = 'Write recommended_action as a short first paragraph (what to do). Write reasoning as a separate second paragraph (why); it should read naturally as a follow-up, e.g. starting with "Because", "This way", or flowing from the recommendation. No step lists or numbered chains.';

    protected const LOW_CONFIDENCE = 'If unsure, state why in reasoning and use confidence below 0.5.';

    public function version(): string
    {
        return 'v1.3';
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

        return $critical.' '.self::SHORT_PERSONA.' '.self::SHORT_BOUNDARIES.' '.self::TONE.' '.self::LOW_CONFIDENCE;
    }
}
