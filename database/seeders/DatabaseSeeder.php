<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * For full fake data (5 users with projects, tasks, events, tags, comments,
     * activity logs, and cross-user collaborations), run after migrate:fresh:
     *   php artisan db:seed --class=FullFakeDataSeeder
     * Or uncomment the line below to use it as the default seed.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // $this->call(FullFakeDataSeeder::class);
    }
}
