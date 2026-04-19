<?php

namespace Database\Factories;

use App\Models\SchoolClass;
use App\Models\Teacher;
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
            'start_datetime' => $start,
            'end_datetime' => $this->faker->dateTimeBetween($start, '+4 months'),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (SchoolClass $schoolClass): void {
            if ($schoolClass->teacher_id !== null) {
                return;
            }

            $userId = (int) $schoolClass->user_id;
            if ($userId === 0) {
                return;
            }

            $schoolClass->teacher_id = Teacher::firstOrCreateByDisplayName(
                $userId,
                $this->faker->name()
            )->id;
        });
    }
}
