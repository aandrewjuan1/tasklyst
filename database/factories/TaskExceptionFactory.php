<?php

namespace Database\Factories;

use App\Models\RecurringTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TaskException>
 */
class TaskExceptionFactory extends Factory
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
            'exception_date' => fake()->dateTimeBetween('-1 week', '+1 week')->format('Y-m-d'),
            'is_deleted' => false,
            'replacement_instance_id' => null,
            'reason' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
