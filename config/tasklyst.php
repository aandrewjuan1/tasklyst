<?php

return [

    /*
    |--------------------------------------------------------------------------
    | TaskLyst LLM – env reference
    |--------------------------------------------------------------------------
    |
    | All TASKLYST_* env vars used by this config (for feature flags and tuning):
    |
    | LLM inference:
    |   TASKLYST_LLM_MODEL, TASKLYST_LLM_TIMEOUT, TASKLYST_LLM_MAX_TOKENS,
    |   TASKLYST_LLM_MAX_ATTEMPTS, TASKLYST_LLM_TEMPERATURE, TASKLYST_LLM_NUM_CTX,
    |   TASKLYST_LLM_QUEUE, TASKLYST_LLM_RETRY_DELAY_SECONDS,
    |   TASKLYST_LLM_CLASSIFICATION_TIMEOUT, TASKLYST_LLM_HEALTH_CHECK_TIMEOUT
    |
    | Intent classification:
    |   TASKLYST_INTENT_CONFIDENCE_THRESHOLD, TASKLYST_INTENT_LLM_FALLBACK
    |
    | Guardrails:
    |   TASKLYST_GUARDRAILS_RELEVANCE_ENABLED, TASKLYST_GUARDRAILS_RATE_LIMIT_ENABLED,
    |   TASKLYST_GUARDRAILS_RATE_LIMIT_PER_MINUTE
    |
    | Context:
    |   TASKLYST_CONTEXT_MAX_TOKENS, TASKLYST_CONTEXT_SAFETY_MARGIN_RATIO,
    |   TASKLYST_CONTEXT_TASK_LIMIT, TASKLYST_CONTEXT_EVENT_LIMIT,
    |   TASKLYST_CONTEXT_PROJECT_LIMIT, TASKLYST_CONTEXT_PROJECT_TASKS_LIMIT,
    |   TASKLYST_CONTEXT_CONVERSATION_HISTORY_LIMIT,
    |   TASKLYST_CONTEXT_CONVERSATION_HISTORY_MESSAGE_MAX_CHARS,
    |   TASKLYST_CONTEXT_RESOLVE_DEPENDENCY_LIMIT,
    |   TASKLYST_CONTEXT_GENERAL_QUERY_TASK_LIMIT, _EVENT_LIMIT, _PROJECT_LIMIT, _PROJECT_TASKS_LIMIT
    |
    */

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
        // Delay in seconds before retrying inference after a failed attempt (when max_attempts > 1).
        'retry_delay_seconds' => (int) env('TASKLYST_LLM_RETRY_DELAY_SECONDS', 2),
        // Short timeout for optional intent-classification LLM call; fallback to regex on timeout/failure.
        'classification_timeout' => (int) env('TASKLYST_LLM_CLASSIFICATION_TIMEOUT', 10),
        // Timeout for Ollama health check; keep low so jobs fail fast when Ollama is down.
        'health_check_timeout' => (int) env('TASKLYST_LLM_HEALTH_CHECK_TIMEOUT', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Intent classification
    |--------------------------------------------------------------------------
    |
    | When regex/keyword confidence is below confidence_threshold, the system
    | may use an optional second-pass LLM classification (if use_llm_fallback).
    | Default: regex-only (use_llm_fallback false) for better performance;
    | set to true and tune threshold when you need higher intent accuracy.
    |
    */
    'intent' => [
        'confidence_threshold' => (float) env('TASKLYST_INTENT_CONFIDENCE_THRESHOLD', 0.75),
        'use_llm_fallback' => env('TASKLYST_INTENT_LLM_FALLBACK', false),
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
        'relevance_blocklist' => [
            'tanginamo',
            // Terms that force "please rephrase" even when combined with domain keywords (e.g. "tanginamo tasks").
            // Add more via config merge or a dedicated config file if needed.
        ],
        // Optional rate limit: max LLM requests per user per window (window = rate_limit_decay_seconds).
        'rate_limit_enabled' => (bool) env('TASKLYST_GUARDRAILS_RATE_LIMIT_ENABLED', false),
        'rate_limit_per_minute' => (int) env('TASKLYST_GUARDRAILS_RATE_LIMIT_PER_MINUTE', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context preparation (Phase 3)
    |--------------------------------------------------------------------------
    |
    | Limits and token budget for LLM context payload. System prompt uses
    | ~300-400 tokens; keep context payload within max_tokens so total
    | prompt stays within model context window. Target cap uses a safety
    | margin (e.g. 0.9) so total prompt fits reliably.
    |
    */
    'context' => [
        'max_tokens' => (int) env('TASKLYST_CONTEXT_MAX_TOKENS', 1200),
        // Target context at 90% of max_tokens to leave room for system + user message.
        'safety_margin_ratio' => (float) env('TASKLYST_CONTEXT_SAFETY_MARGIN_RATIO', 0.9),
        'task_limit' => (int) env('TASKLYST_CONTEXT_TASK_LIMIT', 12),
        'event_limit' => (int) env('TASKLYST_CONTEXT_EVENT_LIMIT', 10),
        'project_limit' => (int) env('TASKLYST_CONTEXT_PROJECT_LIMIT', 5),
        'project_tasks_limit' => (int) env('TASKLYST_CONTEXT_PROJECT_TASKS_LIMIT', 10),
        'conversation_history_limit' => (int) env('TASKLYST_CONTEXT_CONVERSATION_HISTORY_LIMIT', 5),
        // Max characters per message in conversation_history to avoid blowing token budget.
        'conversation_history_message_max_chars' => (int) env('TASKLYST_CONTEXT_CONVERSATION_HISTORY_MESSAGE_MAX_CHARS', 200),
        // resolve_dependency and adjust_* intents use smaller entity sets to keep prompts lean.
        'resolve_dependency_entity_limit' => (int) env('TASKLYST_CONTEXT_RESOLVE_DEPENDENCY_LIMIT', 5),
        // Smaller limits for general_query so the LLM has task/event/project awareness without blowing token budget.
        'general_query_task_limit' => (int) env('TASKLYST_CONTEXT_GENERAL_QUERY_TASK_LIMIT', 8),
        'general_query_event_limit' => (int) env('TASKLYST_CONTEXT_GENERAL_QUERY_EVENT_LIMIT', 6),
        'general_query_project_limit' => (int) env('TASKLYST_CONTEXT_GENERAL_QUERY_PROJECT_LIMIT', 3),
        'general_query_project_tasks_limit' => (int) env('TASKLYST_CONTEXT_GENERAL_QUERY_PROJECT_TASKS_LIMIT', 5),
    ],

];
