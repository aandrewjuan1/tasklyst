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
        /*
        | Prioritize listing narrative (structured JSON: framing, reasoning, etc.).
        | Tuned for small local models (default hermes3:3b): warm enough for coach-like
        | voice, low enough to respect schema and reduce rambling. Prefer temperature
        | only—set TASK_ASSISTANT_PRIORITIZE_NARRATIVE_TOP_P only if you know your
        | stack benefits from nucleus sampling alongside temperature.
        */
        'prioritize_narrative' => [
            'temperature' => (float) env('TASK_ASSISTANT_PRIORITIZE_NARRATIVE_TEMPERATURE', 0.38),
            'max_tokens' => (int) env('TASK_ASSISTANT_PRIORITIZE_NARRATIVE_MAX_TOKENS', 900),
            'top_p' => env('TASK_ASSISTANT_PRIORITIZE_NARRATIVE_TOP_P'),
        ],
        'general_guidance' => [
            'temperature' => env('TASK_ASSISTANT_GENERAL_GUIDANCE_TEMPERATURE', 0.35),
            'max_tokens' => env('TASK_ASSISTANT_GENERAL_GUIDANCE_MAX_TOKENS', 500),
            'top_p' => env('TASK_ASSISTANT_GENERAL_GUIDANCE_TOP_P', 0.9),
        ],
        'general_guidance_target' => [
            'temperature' => env('TASK_ASSISTANT_GENERAL_GUIDANCE_TARGET_TEMPERATURE', 0.12),
            'max_tokens' => env('TASK_ASSISTANT_GENERAL_GUIDANCE_TARGET_MAX_TOKENS', 200),
            'top_p' => env('TASK_ASSISTANT_GENERAL_GUIDANCE_TARGET_TOP_P', 0.85),
        ],
        'listing_narrative' => [
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
        'prioritize_variant' => [
            'temperature' => env('TASK_ASSISTANT_PRIORITIZE_VARIANT_TEMPERATURE', 0.12),
            'max_tokens' => env('TASK_ASSISTANT_PRIORITIZE_VARIANT_MAX_TOKENS', 200),
            'top_p' => env('TASK_ASSISTANT_PRIORITIZE_VARIANT_TOP_P', 0.85),
        ],
        'listing' => [
            'temperature' => env('TASK_ASSISTANT_BROWSE_TEMPERATURE'),
            'max_tokens' => env('TASK_ASSISTANT_BROWSE_MAX_TOKENS'),
            'top_p' => env('TASK_ASSISTANT_BROWSE_TOP_P'),
        ],
    ],

    'rate_limit' => [
        'submissions_per_minute' => (int) env('TASK_ASSISTANT_SUBMISSIONS_PER_MINUTE', 15),
    ],

    'queue' => env('TASK_ASSISTANT_QUEUE', 'task-assistant'),

    /*
    |--------------------------------------------------------------------------
    | UI Stream Rendering
    |--------------------------------------------------------------------------
    |
    | Controls post-generation chunk streaming behavior. This does not stream
    | LLM tokens directly; it renders final assistant text in paced deltas so
    | chat feels live without adding large delays.
    |
    */
    'streaming' => [
        'chunk_size' => (int) env('TASK_ASSISTANT_STREAM_CHUNK_SIZE', 40),
        'enable_typing_effect' => (bool) env('TASK_ASSISTANT_ENABLE_TYPING_EFFECT', true),
        'inter_chunk_delay_ms' => (int) env('TASK_ASSISTANT_INTER_CHUNK_DELAY_MS', 24),
        'max_typing_effect_ms' => (int) env('TASK_ASSISTANT_MAX_TYPING_EFFECT_MS', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule horizon (deterministic placement window)
    |--------------------------------------------------------------------------
    |
    | max_horizon_days caps multi-day search ranges. Week boundaries use ISO Monday.
    |
    */
    'schedule' => [
        'max_horizon_days' => (int) env('TASK_ASSISTANT_SCHEDULE_MAX_HORIZON_DAYS', 14),
    ],

    'tools' => [
        'routes' => [
            'listing' => [],
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
    | listing_route_context: extra system prompt line when the resolved listing flow runs.
    |
    */
    'listing' => [
        'snapshot_task_limit' => (int) env('TASK_ASSISTANT_BROWSE_SNAPSHOT_TASK_LIMIT', 200),
        'ambiguous_top_limit' => (int) env('TASK_ASSISTANT_BROWSE_AMBIGUOUS_TOP', 5),
        'max_items' => (int) env('TASK_ASSISTANT_BROWSE_MAX_ITEMS', 50),
        /** Must match Prism prioritize listing narrative output clamp and response validation. */
        'max_reasoning_chars' => (int) env('TASK_ASSISTANT_BROWSE_MAX_REASONING_CHARS', 1200),
        /** Prioritize `framing` max length; must stay in sync with TaskAssistantListingDefaults::maxFramingChars() and validation. */
        'max_framing_chars' => (int) env('TASK_ASSISTANT_MAX_FRAMING_CHARS', 900),
        /** Rank-flow deterministic `doing_progress_coach`; must match TaskAssistantListingDefaults::maxDoingProgressCoachChars() and validation. */
        'max_doing_progress_coach_chars' => (int) env('TASK_ASSISTANT_MAX_DOING_PROGRESS_COACH_CHARS', 600),
        /** Single-paragraph recommendations field (suggested_guidance). */
        'max_suggested_guidance_chars' => (int) env('TASK_ASSISTANT_BROWSE_MAX_SUGGESTED_GUIDANCE_CHARS', 1200),
        /** Per-row LLM placement line merged into items[].placement_blurb; must match validator max. */
        'max_item_placement_blurb_chars' => (int) env('TASK_ASSISTANT_MAX_ITEM_PLACEMENT_BLURB_CHARS', 200),
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

    'listing_route_context' => <<<'TXT'
Listing mode (read-only listing): Task order and membership are fixed. Speak as the assistant: "you/your" or neutral. Never say snapshot, snapshot data, JSON, backend, or database in student-visible text.

Reasoning: briefly why this list matches their request—only use titles and dates from the task list.

Suggested guidance: one paragraph starting with "I suggest" or "I recommend"; warm tips (time management, avoiding overwhelm). No bullets. No invented durations.

Do not suggest calendar blocking unless they asked about scheduling.
TXT,

    'intent' => [
        'use_llm' => env('TASK_ASSISTANT_INTENT_USE_LLM', true),
        'off_topic_min_confidence' => (float) env('TASK_ASSISTANT_OFF_TOPIC_MIN_CONFIDENCE', 0.65),
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
            // When the confidence gap between the top two candidate composites
            // is small, prefer general guidance to avoid wrong hard routing.
            'ambiguity_gap_min' => (float) env('TASK_ASSISTANT_INTENT_AMBIGUITY_GAP_MIN', 0.15),
            // Require the second-best composite to be meaningfully non-zero.
            'ambiguity_second_composite_min' => (float) env('TASK_ASSISTANT_INTENT_AMBIGUITY_SECOND_COMPOSITE_MIN', 0.12),
            // Keep general-guidance override limited to medium confidence.
            'ambiguity_top_composite_max' => (float) env('TASK_ASSISTANT_INTENT_AMBIGUITY_TOP_COMPOSITE_MAX', 0.65),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Prioritize flow (rank vs browse vs follow-up slice)
    |--------------------------------------------------------------------------
    |
    | When a message is ambiguous (list/show wording plus prioritize/focus),
    | a small structured classifier can disambiguate. Disable in tests or
    | low-latency environments via env.
    |
    */
    'prioritize' => [
        'use_variant_classifier' => (bool) env('TASK_ASSISTANT_PRIORITIZE_USE_VARIANT_CLASSIFIER', true),
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
