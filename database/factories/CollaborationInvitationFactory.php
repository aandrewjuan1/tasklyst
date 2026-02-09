<?php

namespace Database\Factories;

use App\Enums\CollaborationPermission;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CollaborationInvitation>
 */
class CollaborationInvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'collaboratable_type' => Task::class,
            'collaboratable_id' => Task::factory(),
            'inviter_id' => User::factory(),
            'invitee_email' => $this->faker->unique()->safeEmail(),
            'invitee_user_id' => null,
            'permission' => CollaborationPermission::View,
            'status' => 'pending',
            'expires_at' => null,
        ];
    }
}
