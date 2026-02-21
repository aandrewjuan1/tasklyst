<?php

namespace Database\Factories;

use App\Enums\TaskComplexity;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'project_id' => null,
            'event_id' => null,
            'parent_task_id' => null,
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->optional()->sentence(),
            'status' => $this->faker->randomElement(TaskStatus::cases()),
            'priority' => $this->faker->randomElement(TaskPriority::cases()),
            'complexity' => $this->faker->randomElement(TaskComplexity::cases()),
            'duration' => $this->faker->optional()->numberBetween(15, 240),
            'start_datetime' => $this->faker->optional()->dateTimeBetween('-1 week', '+1 week'),
            'end_datetime' => $this->faker->optional()->dateTimeBetween('+1 hour', '+2 weeks'),
        ];
    }
}
