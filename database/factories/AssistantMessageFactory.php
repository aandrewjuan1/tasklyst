<?php

namespace Database\Factories;

use App\Models\AssistantThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AssistantMessage>
 */
class AssistantMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assistant_thread_id' => AssistantThread::factory(),
            'role' => 'user',
            'content' => $this->faker->sentence(),
            'metadata' => null,
        ];
    }

    public function user(): static
    {
        return $this->state(fn (array $attributes) => ['role' => 'user']);
    }

    public function assistant(): static
    {
        return $this->state(fn (array $attributes) => ['role' => 'assistant']);
    }

    public function withMetadata(array $metadata): static
    {
        return $this->state(fn (array $attributes) => ['metadata' => $metadata]);
    }
}
