<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Brightspace calendar import window (past)
    |--------------------------------------------------------------------------
    |
    | Events whose effective end is strictly before this rolling cutoff
    | (start of today minus N calendar months) are skipped on sync.
    | Users may override with users.calendar_import_past_months; null uses default.
    |
    */

    'default_import_past_months' => 3,

    /**
     * @var list<int>
     */
    'allowed_import_past_months' => [1, 3, 6],

    /*
    |--------------------------------------------------------------------------
    | Brightspace task upsert chunk size
    |--------------------------------------------------------------------------
    |
    | Number of rows per upsert batch when importing calendar events into tasks.
    |
    */

    'task_upsert_chunk_size' => 150,

];
