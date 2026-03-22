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
    ],

    'browse_route_context' => 'Browse mode: task lists are produced by backend filtering and ranking from the user snapshot. Narration only refines wording; it does not change which tasks were selected. Offer helpful next steps such as prioritizing or scheduling when relevant.',

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
