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
        'timeout' => (int) env('TASKLYST_LLM_TIMEOUT', 30),
        'max_tokens' => (int) env('TASKLYST_LLM_MAX_TOKENS', 1024),
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

];
