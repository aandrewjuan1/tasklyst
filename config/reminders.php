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

    'school_class_start_soon_offsets_minutes' => [
        15,
        60,
    ],

    'school_class_ending_soon_offsets_minutes' => [
        10,
    ],

    'daily_due_summary_hour' => 7,
    'task_stalled_hours' => 72,
    'project_deadline_risk_days' => 7,
    'project_deadline_risk_min_open_tasks' => 3,
    'recurrence_anomaly_window_days' => 14,
    'recurrence_anomaly_min_exceptions' => 3,
    'collaboration_invite_expiring_hours_before' => 24,
    'calendar_feed_stale_sync_hours' => 6,
    'focus_drift_weekly_day_of_week' => 1,
    'focus_drift_weekly_hour' => 8,
    'assistant_action_required_cooldown_minutes' => 30,

    /*
    |--------------------------------------------------------------------------
    | Dedupe / cooldown windows
    |--------------------------------------------------------------------------
    */

    'calendar_feed_sync_failed_cooldown_minutes' => 60,
    'calendar_feed_stale_sync_cooldown_minutes' => 180,
    'calendar_feed_recovered_cooldown_minutes' => 180,

    /*
    |--------------------------------------------------------------------------
    | Dispatching
    |--------------------------------------------------------------------------
    */

    'dispatch' => [
        'default_limit' => 200,
        'per_remindable_limit' => 25,
        'retry_delay_minutes' => 5,
        'max_attempts' => 3,
    ],
];
