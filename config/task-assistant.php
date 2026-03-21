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
        'context' => [
            'temperature' => env('TASK_ASSISTANT_CONTEXT_TEMPERATURE'),
            'max_tokens' => env('TASK_ASSISTANT_CONTEXT_MAX_TOKENS'),
            'top_p' => env('TASK_ASSISTANT_CONTEXT_TOP_P'),
        ],
    ],

    'rate_limit' => [
        'submissions_per_minute' => (int) env('TASK_ASSISTANT_SUBMISSIONS_PER_MINUTE', 15),
    ],

    'tools' => [
        'routes' => [
            'chat' => ['list_tasks'],
            'schedule' => ['list_tasks'],
            'prioritize' => [],
        ],
    ],

    'routing' => [
        'policy_enabled' => env('TASK_ASSISTANT_POLICY_ROUTING_ENABLED', false),
        'execute_threshold' => (float) env('TASK_ASSISTANT_ROUTING_EXECUTE_THRESHOLD', 0.7),
        'clarify_threshold' => (float) env('TASK_ASSISTANT_ROUTING_CLARIFY_THRESHOLD', 0.45),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tool Decision Policy
    |--------------------------------------------------------------------------
    |
    | Controls when the assistant should execute tools, ask clarification,
    | or stay in response-only mode. This keeps behavior natural while still
    | enforcing backend safety for tool execution.
    |
    */
    'tool_decision' => [
        'enabled' => env('TASK_ASSISTANT_TOOL_DECISION_ENABLED', true),
        'execute_threshold' => (float) env('TASK_ASSISTANT_TOOL_EXECUTE_THRESHOLD', 0.75),
        'clarify_threshold' => (float) env('TASK_ASSISTANT_TOOL_CLARIFY_THRESHOLD', 0.45),
        'allow_read_only_on_advisory' => env('TASK_ASSISTANT_ALLOW_READ_ONLY_ON_ADVISORY', true),
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
