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
    |   TASKLYST_CONTEXT_DESCRIPTION_MAX_CHARS_SLIM, TASKLYST_CONTEXT_DESCRIPTION_MAX_CHARS_FULL,
    |   TASKLYST_CONTEXT_RESOLVE_DEPENDENCY_LIMIT,
    |   TASKLYST_CONTEXT_GENERAL_QUERY_TASK_LIMIT, _EVENT_LIMIT, _PROJECT_LIMIT, _PROJECT_TASKS_LIMIT
    |   TASKLYST_FAKE_DATA_LEVEL (easy | realistic | nightmare) for FullFakeDataSeeder.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Fake data level (FullFakeDataSeeder)
    |--------------------------------------------------------------------------
    |
    | Controls how much messy/edge-case data is seeded for LLM stress-testing.
    | easy: mostly clean data. realistic: mix of clean, messy, conflicting,
    | incomplete. nightmare: more duplicates, nulls, overlaps, impossible tasks.
    |
    */
    'fake_data_level' => env('TASKLYST_FAKE_DATA_LEVEL', 'realistic'),

    /*
    |--------------------------------------------------------------------------
    | LLM (TaskLyst assistant) configuration
    |--------------------------------------------------------------------------
    |
    | Tuned for Hermes 3 3B (NousResearch/Hermes-3-Llama-3.2-3B) via Ollama
    | (model name: hermes3:3b). Prism sends system + user messages; Ollama
    | applies the model's ChatML template. Structured output uses Ollama's
    | "format" (JSON schema); prompts are aligned with Hermes 3 JSON mode.
    |
    | num_ctx: keep within model context (e.g. 4096 for 3B); context payload
    | is capped separately so system + context + user fit.
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
    | Default: true for multi-turn support (e.g. "how about in events?" follow-ups);
    | set to false for regex-only when you need lower latency.
    |
    */
    'intent' => [
        'confidence_threshold' => (float) env('TASKLYST_INTENT_CONFIDENCE_THRESHOLD', 0.75),
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
    | Limits and token budget for LLM context payload. Tuned for Hermes 3 3B:
    | system prompt ~300-400 tokens; context within max_tokens so system +
    | context + user message fit in num_ctx (e.g. 4096). Safety margin keeps
    | total prompt under the cap.
    |
    */
    'context' => [
        'max_tokens' => (int) env('TASKLYST_CONTEXT_MAX_TOKENS', 1200),
        // Target context at 90% of max_tokens to leave room for system + user message.
        'safety_margin_ratio' => (float) env('TASKLYST_CONTEXT_SAFETY_MARGIN_RATIO', 0.9),
        // Max characters for description fields; slim used for prioritize intents, full for schedule/adjust/general.
        'description_max_chars_slim' => (int) env('TASKLYST_CONTEXT_DESCRIPTION_MAX_CHARS_SLIM', 80),
        'description_max_chars_full' => (int) env('TASKLYST_CONTEXT_DESCRIPTION_MAX_CHARS_FULL', 200),
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
        // Multi-entity prioritization (e.g. prioritize_tasks_and_events): reduced per-entity limits.
        'multi_entity_task_limit' => (int) env('TASKLYST_CONTEXT_MULTI_ENTITY_TASK_LIMIT', 6),
        'multi_entity_event_limit' => (int) env('TASKLYST_CONTEXT_MULTI_ENTITY_EVENT_LIMIT', 6),
        'multi_entity_project_limit' => (int) env('TASKLYST_CONTEXT_MULTI_ENTITY_PROJECT_LIMIT', 4),
        'multi_entity_project_tasks_limit' => (int) env('TASKLYST_CONTEXT_MULTI_ENTITY_PROJECT_TASKS_LIMIT', 3),
        // Prioritize all (tasks + events + projects): smallest per-entity limits.
        'multi_entity_all_task_limit' => (int) env('TASKLYST_CONTEXT_MULTI_ENTITY_ALL_TASK_LIMIT', 4),
        'multi_entity_all_event_limit' => (int) env('TASKLYST_CONTEXT_MULTI_ENTITY_ALL_EVENT_LIMIT', 4),
        'multi_entity_all_project_limit' => (int) env('TASKLYST_CONTEXT_MULTI_ENTITY_ALL_PROJECT_LIMIT', 3),
        // Multi-entity scheduling (two-entity combos): reduced per-entity limits.
        'multi_entity_schedule_task_limit' => (int) env('TASKLYST_CONTEXT_MULTI_ENTITY_SCHEDULE_TASK_LIMIT', 5),
        'multi_entity_schedule_event_limit' => (int) env('TASKLYST_CONTEXT_MULTI_ENTITY_SCHEDULE_EVENT_LIMIT', 5),
        'multi_entity_schedule_project_limit' => (int) env('TASKLYST_CONTEXT_MULTI_ENTITY_SCHEDULE_PROJECT_LIMIT', 3),
        // Schedule all (tasks + events + projects): smallest per-entity limits for schedule.
        'multi_entity_schedule_all_task_limit' => (int) env('TASKLYST_CONTEXT_MULTI_ENTITY_SCHEDULE_ALL_TASK_LIMIT', 4),
        'multi_entity_schedule_all_event_limit' => (int) env('TASKLYST_CONTEXT_MULTI_ENTITY_SCHEDULE_ALL_EVENT_LIMIT', 4),
        'multi_entity_schedule_all_project_limit' => (int) env('TASKLYST_CONTEXT_MULTI_ENTITY_SCHEDULE_ALL_PROJECT_LIMIT', 3),
        // Scheduling: how many days ahead to include; all days are listed (empty busy_windows = free).
        'availability_days' => (int) env('TASKLYST_CONTEXT_AVAILABILITY_DAYS', 7),
        'availability_max_windows_per_day' => (int) env('TASKLYST_CONTEXT_AVAILABILITY_MAX_WINDOWS_PER_DAY', 12),
    ],

];
