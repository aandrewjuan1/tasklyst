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
        $startCarbon = \Illuminate\Support\Carbon::instance($start);
        $endCarbon = $startCarbon->copy()->addMinutes($this->faker->numberBetween(45, 180));

        return [
            'user_id' => User::factory(),
            'subject_name' => $this->faker->words(3, true),
            'start_time' => $startCarbon->format('H:i:s'),
            'end_time' => $endCarbon->format('H:i:s'),
            'start_datetime' => $startCarbon,
            'end_datetime' => $endCarbon,
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
