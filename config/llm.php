<?php

return [
    // Model
    'model' => env('LLM_MODEL', 'hermes3:3b'),
    'temperature' => (float) env('LLM_TEMPERATURE', 0.45),
    'top_p' => (float) env('LLM_TOP_P', 0.95),
    'max_tokens' => (int) env('LLM_MAX_TOKENS', 1024),
    'max_tokens_cap' => (int) env('LLM_MAX_TOKENS_CAP', 2048),

    // Schema versioning
    'schema_version' => env('LLM_SCHEMA_VERSION', '2026-03-01.v1'),

    // Context window
    'context' => [
        'max_tasks' => (int) env('LLM_CTX_MAX_TASKS', 8),
        'max_events_hours' => (int) env('LLM_CTX_MAX_EVENTS_HOURS', 24),
        'recent_messages' => (int) env('LLM_CTX_RECENT_MESSAGES', 6),
        'summary_task_threshold' => (int) env('LLM_CTX_SUMMARY_THRESHOLD', 50),
        'token_budget' => (int) env('LLM_CTX_TOKEN_BUDGET', 2000),
        'max_projects' => (int) env('LLM_CTX_MAX_PROJECTS', 5),
    ],

    // Single repair attempt only
    'repair' => [
        'max_attempts' => 1,
    ],

    // Confidence thresholds
    'confidence' => [
        'low_threshold' => (float) env('LLM_CONFIDENCE_LOW', 0.4),
        'high_threshold' => (float) env('LLM_CONFIDENCE_HIGH', 0.75),
    ],

    // Prompt behavior
    'prompt' => [
        'default_style' => env('LLM_PROMPT_DEFAULT_STYLE', 'balanced'),
        'reasoning_word_limit' => (int) env('LLM_PROMPT_REASONING_WORD_LIMIT', 25),
        'reasoning_word_limit_for_prioritize' => (int) env('LLM_PROMPT_REASONING_WORD_LIMIT_PRIORITIZE', 50),
        'prioritize_default_limit' => (int) env('LLM_PROMPT_PRIORITIZE_LIMIT', 5),
        'include_next_steps' => (bool) env('LLM_PROMPT_INCLUDE_NEXT_STEPS', true),
        'allow_inline_bullets' => (bool) env('LLM_PROMPT_ALLOW_INLINE_BULLETS', true),
        'require_clarification_for_ambiguous_time' => (bool) env('LLM_PROMPT_REQUIRE_CLARIFY_TIME', true),
        'use_titles_in_message' => (bool) env('LLM_PROMPT_USE_TITLES_IN_MESSAGE', true),
        'show_rank_numbers' => (bool) env('LLM_PROMPT_SHOW_RANK_NUMBERS', true),
        'show_ids_in_message' => (bool) env('LLM_PROMPT_SHOW_IDS_IN_MESSAGE', false),
        'message' => [
            'min_sentences' => (int) env('LLM_PROMPT_MIN_SENTENCES', 1),
            'max_sentences' => (int) env('LLM_PROMPT_MAX_SENTENCES', 4),
        ],
        'domain_guardrails' => [
            'enabled' => (bool) env('LLM_PROMPT_DOMAIN_GUARDRAILS_ENABLED', true),
            'block_politics' => (bool) env('LLM_PROMPT_BLOCK_POLITICS', true),
            'block_out_of_scope_qa' => (bool) env('LLM_PROMPT_BLOCK_OUT_OF_SCOPE_QA', true),
            'min_confidence_for_productivity' => (float) env('LLM_PROMPT_MIN_CONFIDENCE_PRODUCTIVITY', 0.2),
        ],
        'intent_tuning' => [
            'prioritize' => (float) env('LLM_PROMPT_INTENT_TEMPERATURE_PRIORITIZE', 0.5),
            'schedule' => (float) env('LLM_PROMPT_INTENT_TEMPERATURE_SCHEDULE', 0.35),
            'create' => (float) env('LLM_PROMPT_INTENT_TEMPERATURE_CREATE', 0.45),
            'update' => (float) env('LLM_PROMPT_INTENT_TEMPERATURE_UPDATE', 0.35),
        ],
        'low_confidence_hint' => env('LLM_PROMPT_LOW_CONFIDENCE_HINT', 'This is a best-effort suggestion; you may want to double-check the details.'),
        'chatml' => [
            'enabled' => (bool) env('LLM_PROMPT_CHATML_ENABLED', false),
        ],
    ],

    // Queue
    'queue' => [
        'connection' => env('LLM_QUEUE_CONNECTION', 'redis'),
        'name' => env('LLM_QUEUE_NAME', 'llm'),
        'timeout' => (int) env('LLM_QUEUE_TIMEOUT', 90),
        'tries' => (int) env('LLM_QUEUE_TRIES', 2),
    ],

    // Rate limiting (per user)
    'rate_limit' => [
        'max_requests' => (int) env('LLM_RATE_MAX', 30),
        'per_minutes' => (int) env('LLM_RATE_MINUTES', 10),
    ],

    // Logging
    'log' => [
        'channel' => env('LLM_LOG_CHANNEL', 'stack'),
        'raw_retention_days' => (int) env('LLM_RAW_RETENTION_DAYS', 90),
    ],

    // Whitelisted tool names
    'allowed_tools' => [
        'create_task',
        'update_task',
        'create_event',
    ],

    // Timezone
    'timezone' => env('LLM_TIMEZONE', 'Asia/Manila'),
];
