<?php

namespace Database\Factories;

use App\Enums\FocusSessionType;
use App\Models\FocusSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FocusSession>
 */
class FocusSessionFactory extends Factory
{
    protected $model = FocusSession::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'focusable_type' => null,
            'focusable_id' => null,
            'type' => FocusSessionType::Work,
            'sequence_number' => 1,
            'duration_seconds' => 1500,
            'completed' => false,
            'started_at' => now(),
            'ended_at' => null,
            'paused_seconds' => 0,
            'payload' => null,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'completed' => false,
            'ended_at' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'completed' => true,
            'ended_at' => now(),
        ]);
    }
}
