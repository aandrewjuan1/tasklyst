<?php

return [
    // Model
    'model' => env('LLM_MODEL', 'hermes3:3b'),
    'temperature' => (float) env('LLM_TEMPERATURE', 0.2),
    'top_p' => (float) env('LLM_TOP_P', 0.9),
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
        'create_schedule',
    ],

    // Timezone
    'timezone' => env('LLM_TIMEZONE', 'Asia/Manila'),
];
