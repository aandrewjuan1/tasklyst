<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Reminder scheduling defaults
    |--------------------------------------------------------------------------
    |
    | These defaults power backend reminder generation. UI may later allow
    | per-user overrides, but the backend should remain deterministic.
    |
    */

    'task_due_soon_offsets_minutes' => [
        60,
        24 * 60,
    ],

    'event_start_soon_offsets_minutes' => [
        15,
        60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dedupe / cooldown windows
    |--------------------------------------------------------------------------
    */

    'calendar_feed_sync_failed_cooldown_minutes' => 60,

    /*
    |--------------------------------------------------------------------------
    | Dispatching
    |--------------------------------------------------------------------------
    */

    'dispatch' => [
        'default_limit' => 200,
        'retry_delay_minutes' => 5,
        'max_attempts' => 3,
    ],
];
