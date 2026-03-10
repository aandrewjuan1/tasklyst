<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Requires a user with email andrew.juan.cvt@eac.edu.ph to exist (e.g. sign up first).
     * All fake data is created for that user. Run: php artisan db:seed
     */
    public function run(): void
    {
        $this->call([
            StudentLifeSampleSeeder::class,
        ]);
    }
}
