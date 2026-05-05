<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Presentation seeder target (AndrewJuanPresentationSeeder)
    |--------------------------------------------------------------------------
    |
    | Must match `users.email` for the demo account. WorkOS may persist email
    | with different casing than this default; the seeder also matches
    | case-insensitively. Override in production if the account uses another address.
    |
    */
    'presentation_seeder_target_email' => env('PRESENTATION_SEEDER_TARGET_EMAIL', 'andrew.juan.cvt@eac.edu.ph'),

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
