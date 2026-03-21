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

    'model' => env('TASK_ASSISTANT_MODEL', 'hermes3:3b'),

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
