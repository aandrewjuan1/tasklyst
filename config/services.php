<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'workos' => [
        'client_id' => env('WORKOS_CLIENT_ID'),
        'secret' => env('WORKOS_API_KEY'),
        'redirect_url' => env('WORKOS_REDIRECT_URL'),
        'provider' => env('WORKOS_PROVIDER', 'authkit'),

        /*
        | Session validation result is cached per session to avoid calling WorkOS
        | on every request. This TTL (minutes) controls how long we skip the
        | remote validation after a successful check.
        */
        'session_validation_cache_ttl_minutes' => (int) env('WORKOS_SESSION_VALIDATION_CACHE_TTL_MINUTES', 5),
    ],

    'ai_proxy' => [
        'token' => env('AI_PROXY_TOKEN', ''),
        'upstream_url' => env('AI_PROXY_UPSTREAM_URL', 'http://127.0.0.1:11434'),
        'default_model' => env('AI_PROXY_DEFAULT_MODEL', 'hermes3:3b'),
    ],

    'ollama_proxy' => [
        'url' => env('OLLAMA_PROXY_URL', ''),
        'token' => env('OLLAMA_PROXY_TOKEN', ''),
        'default_model' => env('OLLAMA_PROXY_DEFAULT_MODEL', env('TASK_ASSISTANT_MODEL', 'hermes3:3b')),
    ],

];
