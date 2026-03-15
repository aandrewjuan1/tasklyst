<?php

namespace Database\Factories;

use App\Enums\MessageRole;
use App\Models\TaskAssistantThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TaskAssistantMessage>
 */
class TaskAssistantMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_id' => TaskAssistantThread::factory(),
            'role' => $this->faker->randomElement(MessageRole::cases()),
            'content' => $this->faker->sentence(),
            'tool_calls' => null,
            'metadata' => [],
        ];
    }
}
