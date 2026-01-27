<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Event>
 */
class EventFactory extends Factory
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
            'title' => $this->faker->sentence(3),
            'description' => $this->faker->optional()->sentence(),
            'start_datetime' => $this->faker->optional()->dateTimeBetween('-1 week', '+1 week'),
            'end_datetime' => $this->faker->optional()->dateTimeBetween('+1 hour', '+2 weeks'),
            'all_day' => $this->faker->boolean(),
            'timezone' => $this->faker->timezone(),
            'location' => $this->faker->optional()->city(),
            'color' => $this->faker->optional()->hexColor(),
            'status' => $this->faker->randomElement(EventStatus::cases()),
        ];
    }
}
