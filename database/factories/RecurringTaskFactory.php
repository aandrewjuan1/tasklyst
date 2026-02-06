<?php

namespace Database\Factories;

use App\Enums\TaskRecurrenceType;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RecurringTask>
 */
class RecurringTaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDatetime = fake()->dateTimeBetween('-1 month', '+1 month');

        return [
            'task_id' => Task::factory(),
            'recurrence_type' => TaskRecurrenceType::Daily,
            'interval' => 1,
            'start_datetime' => $startDatetime,
            'end_datetime' => fake()->optional(0.3)->dateTimeBetween($startDatetime, '+3 months'),
            'days_of_week' => null,
        ];
    }
}
