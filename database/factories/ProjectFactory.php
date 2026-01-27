<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
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
            'name' => $this->faker->sentence(3),
            'description' => $this->faker->optional()->sentence(),
            'start_datetime' => $this->faker->optional()->dateTimeBetween('-1 week', '+1 week'),
            'end_datetime' => $this->faker->optional()->dateTimeBetween('+1 day', '+2 weeks'),
        ];
    }
}
