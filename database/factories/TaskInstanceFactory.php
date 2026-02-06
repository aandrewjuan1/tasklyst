<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\RecurringTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TaskInstance>
 */
class TaskInstanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recurring_task_id' => RecurringTask::factory(),
            'task_id' => null,
            'instance_date' => fake()->dateTimeBetween('-1 week', '+1 week')->format('Y-m-d'),
            'status' => TaskStatus::ToDo,
            'completed_at' => null,
        ];
    }
}
