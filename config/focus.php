<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default focus session duration (minutes)
    |--------------------------------------------------------------------------
    |
    | Used when starting focus mode on a task that has no duration set.
    | Pomodoro settings may override this per user when that feature is enabled.
    |
    */
    'default_duration_minutes' => env('FOCUS_DEFAULT_DURATION_MINUTES', 1),

    /*
    |--------------------------------------------------------------------------
    | Maximum focus session duration (minutes)
    |--------------------------------------------------------------------------
    |
    | Upper limit for a single focus session. Validation enforces this cap.
    |
    */
    'max_duration_minutes' => env('FOCUS_MAX_DURATION_MINUTES', 1440),

];
