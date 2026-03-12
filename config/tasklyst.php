<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Fake data level (FullFakeDataSeeder)
    |--------------------------------------------------------------------------
    |
    | Controls how much messy/edge-case data is seeded.
    | easy: mostly clean data. realistic: mix of clean, messy, conflicting,
    | incomplete. nightmare: more duplicates, nulls, overlaps, impossible tasks.
    |
    */
    'fake_data_level' => env('TASKLYST_FAKE_DATA_LEVEL', 'realistic'),
];
