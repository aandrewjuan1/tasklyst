<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Comment>
 */
class CommentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'commentable_id' => Task::factory(),
            'commentable_type' => Task::class,
            'user_id' => User::factory(),
            'content' => $this->faker->sentence(),
            'is_edited' => false,
            'edited_at' => null,
            'is_pinned' => false,
        ];
    }
}
