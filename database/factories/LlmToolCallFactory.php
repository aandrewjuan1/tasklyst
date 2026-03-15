<?php

namespace Database\Factories;

use App\Enums\LlmToolCallStatus;
use App\Models\TaskAssistantThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LlmToolCall>
 */
class LlmToolCallFactory extends Factory
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
            'message_id' => null,
            'tool_name' => $this->faker->word(),
            'params_json' => [],
            'result_json' => null,
            'status' => $this->faker->randomElement(LlmToolCallStatus::cases()),
            'operation_token' => $this->faker->optional()->uuid(),
            'user_id' => User::factory(),
        ];
    }
}
