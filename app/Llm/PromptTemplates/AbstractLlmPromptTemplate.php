<?php

namespace App\Llm\PromptTemplates;

use App\Llm\Contracts\LlmPromptTemplate;

abstract class AbstractLlmPromptTemplate implements LlmPromptTemplate
{
    protected const RECURRING_CONSTRAINT = 'Do not recommend times that conflict with recurring tasks or events.';

    protected const NO_PAST_TIMES = 'Do not recommend start or end times in the past; use current_time from context.';

    protected const OUTPUT_FORMAT = 'Respond with only valid JSON that matches the provided schema: no markdown, no code fences, no text before or after the JSON. Your response must conform exactly to the schema (required fields and allowed field names).';

    protected const ENTITY_ID_GUARDRAIL = 'Do not include entity_id or any task/event/project IDs in your output; the system resolves the entity from context.';

    protected const CONTEXT_ONLY = 'Use only the context provided (current_time, tasks, events, projects, conversation_history); do not invent dates, tasks, or events.';

    protected const TONE = 'Be concise and confident; explain your reasoning clearly in the reasoning field.';

    protected const LOW_CONFIDENCE = 'If you cannot make a confident recommendation, state why in the reasoning field and use a low confidence score (e.g. below 0.5).';

    public function version(): string
    {
        return 'v1.0';
    }

    /**
     * Shared output rules and guardrails appended to every system prompt.
     */
    protected function outputAndGuardrails(bool $includeNoPastTimes = false): string
    {
        $guardrails = self::OUTPUT_FORMAT.' '.self::ENTITY_ID_GUARDRAIL.' '.self::CONTEXT_ONLY.' '.self::TONE.' '.self::LOW_CONFIDENCE;

        if ($includeNoPastTimes) {
            $guardrails = self::NO_PAST_TIMES.' '.$guardrails;
        }

        return $guardrails;
    }
}
