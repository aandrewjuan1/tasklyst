<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Task Assistant LLM Configuration
    |--------------------------------------------------------------------------
    |
    | Central place for the model used by the Task Assistant module.
    | The Prism provider URL and request timeout remain configured in config/prism.php.
    |
    */

    'provider' => env('TASK_ASSISTANT_PROVIDER', 'ollama'),

    'model' => env('TASK_ASSISTANT_MODEL', 'hermes3:3b'),

    'generation' => [
        'temperature' => (float) env('TASK_ASSISTANT_TEMPERATURE', 0.3),
        'max_tokens' => (int) env('TASK_ASSISTANT_MAX_TOKENS', 1200),
        'top_p' => (float) env('TASK_ASSISTANT_TOP_P', 0.9),
        'chat' => [
            'temperature' => env('TASK_ASSISTANT_CHAT_TEMPERATURE'),
            'max_tokens' => env('TASK_ASSISTANT_CHAT_MAX_TOKENS'),
            'top_p' => env('TASK_ASSISTANT_CHAT_TOP_P'),
        ],
        'schedule' => [
            'temperature' => env('TASK_ASSISTANT_SCHEDULE_TEMPERATURE'),
            'max_tokens' => env('TASK_ASSISTANT_SCHEDULE_MAX_TOKENS'),
            'top_p' => env('TASK_ASSISTANT_SCHEDULE_TOP_P'),
        ],
        'prioritize' => [
            'temperature' => env('TASK_ASSISTANT_PRIORITIZE_TEMPERATURE'),
            'max_tokens' => env('TASK_ASSISTANT_PRIORITIZE_MAX_TOKENS'),
            'top_p' => env('TASK_ASSISTANT_PRIORITIZE_TOP_P'),
        ],
        'prioritize_narrative' => [
            'temperature' => env('TASK_ASSISTANT_PRIORITIZE_NARRATIVE_TEMPERATURE', 0.25),
            'max_tokens' => env('TASK_ASSISTANT_PRIORITIZE_NARRATIVE_MAX_TOKENS', 600),
            'top_p' => env('TASK_ASSISTANT_PRIORITIZE_NARRATIVE_TOP_P', 0.88),
        ],
        'browse_narrative' => [
            'temperature' => env('TASK_ASSISTANT_BROWSE_NARRATIVE_TEMPERATURE', 0.25),
            'max_tokens' => env('TASK_ASSISTANT_BROWSE_NARRATIVE_MAX_TOKENS', 700),
            'top_p' => env('TASK_ASSISTANT_BROWSE_NARRATIVE_TOP_P', 0.88),
        ],
        'context' => [
            'temperature' => env('TASK_ASSISTANT_CONTEXT_TEMPERATURE'),
            'max_tokens' => env('TASK_ASSISTANT_CONTEXT_MAX_TOKENS'),
            'top_p' => env('TASK_ASSISTANT_CONTEXT_TOP_P'),
        ],
        'intent' => [
            'temperature' => env('TASK_ASSISTANT_INTENT_TEMPERATURE', 0.1),
            'max_tokens' => env('TASK_ASSISTANT_INTENT_MAX_TOKENS', 200),
            'top_p' => env('TASK_ASSISTANT_INTENT_TOP_P', 0.85),
        ],
        'browse' => [
            'temperature' => env('TASK_ASSISTANT_BROWSE_TEMPERATURE'),
            'max_tokens' => env('TASK_ASSISTANT_BROWSE_MAX_TOKENS'),
            'top_p' => env('TASK_ASSISTANT_BROWSE_TOP_P'),
        ],
    ],

    'rate_limit' => [
        'submissions_per_minute' => (int) env('TASK_ASSISTANT_SUBMISSIONS_PER_MINUTE', 15),
    ],

    'tools' => [
        'routes' => [
            'chat' => ['list_tasks'],
            'browse' => [],
            'schedule' => ['list_tasks'],
            'prioritize' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Intent routing (LLM + heuristic validation)
    |--------------------------------------------------------------------------
    |
    | This is the only routing pipeline for TaskAssistantService. When use_llm is
    | false, heuristic signals only are used; ambiguous or weak signals fall
    | back to the chat flow.
    |
    | browse_route_context: extra system prompt line when the resolved flow is browse.
    |
    */
    'browse' => [
        'snapshot_task_limit' => (int) env('TASK_ASSISTANT_BROWSE_SNAPSHOT_TASK_LIMIT', 200),
        'ambiguous_top_limit' => (int) env('TASK_ASSISTANT_BROWSE_AMBIGUOUS_TOP', 5),
        'max_items' => (int) env('TASK_ASSISTANT_BROWSE_MAX_ITEMS', 50),
        /** Must match Prism browse narrative output clamp and {@see TaskAssistantResponseProcessor::validateBrowseData}. */
        'max_reasoning_chars' => (int) env('TASK_ASSISTANT_BROWSE_MAX_REASONING_CHARS', 800),
        /** Single-paragraph recommendations field (suggested_guidance). */
        'max_suggested_guidance_chars' => (int) env('TASK_ASSISTANT_BROWSE_MAX_SUGGESTED_GUIDANCE_CHARS', 1200),
        /*
        | School vs chores browse domains use subjects, teachers, and tags — not a raw
        | substring match on the word "school" in titles (avoids false positives like
        | "school bag"). Title patterns below exclude common errands from school lists.
        */
        'school_academic_tag_keywords' => [
            'school', 'academic', 'education', 'course', 'class', 'lecture', 'homework',
            'assignment', 'exam', 'quiz', 'study', 'math', 'science', 'english', 'history',
        ],
        'chore_indicator_tags' => [
            'household', 'health', 'chores', 'cleaning', 'laundry', 'errands',
        ],
        'school_exclusion_title_patterns' => [
            '/\bschool\s+bag\b/i',
            '/\b(groceries|grocery)\b/i',
            '/\b(laundry|dishes|vacuum|trash)\b/i',
            '/\b(pack|packing)\b.*\b(bag|lunch)\b/i',
        ],
    ],

    'browse_route_context' => <<<'TXT'
Browse mode (read-only listing): Task order and membership are fixed. Speak as the assistant: "you/your" or neutral. Never say snapshot, snapshot data, JSON, backend, or database in student-visible text.

Reasoning: briefly why this list matches their request—only use titles and dates from the task list.

Suggested guidance: one paragraph starting with "I suggest" or "I recommend"; warm tips (time management, avoiding overwhelm). No bullets. No invented durations.

Do not suggest calendar blocking unless they asked about scheduling.
TXT,

    'intent' => [
        'use_llm' => env('TASK_ASSISTANT_INTENT_USE_LLM', true),
        'merge' => [
            'llm_weight' => (float) env('TASK_ASSISTANT_INTENT_LLM_WEIGHT', 0.5),
            'signal_weight' => (float) env('TASK_ASSISTANT_INTENT_SIGNAL_WEIGHT', 0.5),
            'validator_override_signal_min' => (float) env('TASK_ASSISTANT_INTENT_OVERRIDE_SIGNAL_MIN', 0.72),
            'llm_weak_below' => (float) env('TASK_ASSISTANT_INTENT_LLM_WEAK_BELOW', 0.55),
            'weak_composite_max' => (float) env('TASK_ASSISTANT_INTENT_WEAK_COMPOSITE_MAX', 0.38),
            'clarify_margin' => (float) env('TASK_ASSISTANT_INTENT_CLARIFY_MARGIN', 0.12),
            'clarify_composite_ceiling' => (float) env('TASK_ASSISTANT_INTENT_CLARIFY_COMPOSITE_CEILING', 0.55),
            'signal_only_weak_max' => (float) env('TASK_ASSISTANT_INTENT_SIGNAL_WEAK_MAX', 0.35),
            'signal_only_clarify_margin' => (float) env('TASK_ASSISTANT_INTENT_SIGNAL_CLARIFY_MARGIN', 0.15),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Policy
    |--------------------------------------------------------------------------
    |
    | Controls how many times structured LLM generation is retried after
    | schema/quality validation failures.
    |
    */
    'retry' => [
        'max_retries' => (int) env('TASK_ASSISTANT_RETRY_MAX_RETRIES', 2),
    ],
];
