<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SchoolClass>
 */
class SchoolClassFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('-1 month', '+1 month');

        return [
            'user_id' => User::factory(),
            'subject_name' => $this->faker->words(3, true),
            'teacher_name' => $this->faker->name(),
            'start_datetime' => $start,
            'end_datetime' => $this->faker->dateTimeBetween($start, '+4 months'),
        ];
    }
}
