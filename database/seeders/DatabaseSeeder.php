<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AndrewJuanExactDatasetSeeder::class,
        ]);

        // Manual run for alternate realistic student data:
        // php artisan db:seed --class=AndrewJuanRealisticSeeder
    }
}
