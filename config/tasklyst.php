<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LLM (TaskLyst assistant) configuration
    |--------------------------------------------------------------------------
    |
    | Model, timeout, and token limits for Prism/Ollama inference. Used by
    | LlmInferenceService and optional intent-classification fallback.
    |
    */
    'llm' => [
        'model' => env('TASKLYST_LLM_MODEL', 'hermes3:3b'),
        'timeout' => (int) env('TASKLYST_LLM_TIMEOUT', 60),
        'max_tokens' => (int) env('TASKLYST_LLM_MAX_TOKENS', 700),
        'max_attempts' => (int) env('TASKLYST_LLM_MAX_ATTEMPTS', 1),
        'temperature' => (float) env('TASKLYST_LLM_TEMPERATURE', 0.3),
        'num_ctx' => (int) env('TASKLYST_LLM_NUM_CTX', 4096),
        'queue' => env('TASKLYST_LLM_QUEUE', 'llm'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Intent classification
    |--------------------------------------------------------------------------
    |
    | When regex/keyword confidence is below this threshold (or no match),
    | the system may use an optional second-pass LLM classification.
    |
    */
    'intent' => [
        'confidence_threshold' => (float) env('TASKLYST_INTENT_CONFIDENCE_THRESHOLD', 0.7),
        'use_llm_fallback' => env('TASKLYST_INTENT_LLM_FALLBACK', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Guardrails
    |--------------------------------------------------------------------------
    |
    | Lightweight, pre-LLM safeguards for the TaskLyst assistant.
    |
    */
    'guardrails' => [
        'relevance_enabled' => (bool) env('TASKLYST_GUARDRAILS_RELEVANCE_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context preparation (Phase 3)
    |--------------------------------------------------------------------------
    |
    | Limits and token budget for LLM context payload. System prompt uses
    | ~300-400 tokens; keep context payload within max_tokens so total
    | prompt stays within model context window.
    |
    */
    'context' => [
        'max_tokens' => (int) env('TASKLYST_CONTEXT_MAX_TOKENS', 1200),
        'task_limit' => (int) env('TASKLYST_CONTEXT_TASK_LIMIT', 12),
        'event_limit' => (int) env('TASKLYST_CONTEXT_EVENT_LIMIT', 10),
        'project_limit' => (int) env('TASKLYST_CONTEXT_PROJECT_LIMIT', 5),
        'project_tasks_limit' => (int) env('TASKLYST_CONTEXT_PROJECT_TASKS_LIMIT', 10),
        'conversation_history_limit' => (int) env('TASKLYST_CONTEXT_CONVERSATION_HISTORY_LIMIT', 5),
        'resolve_dependency_entity_limit' => (int) env('TASKLYST_CONTEXT_RESOLVE_DEPENDENCY_LIMIT', 5),
    ],

];
