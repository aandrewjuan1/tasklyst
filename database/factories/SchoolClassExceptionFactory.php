<?php

namespace Database\Factories;

use App\Models\RecurringSchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SchoolClassException>
 */
class SchoolClassExceptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recurring_school_class_id' => RecurringSchoolClass::factory(),
            'exception_date' => fake()->dateTimeBetween('-1 week', '+1 week')->format('Y-m-d'),
            'is_deleted' => false,
            'replacement_instance_id' => null,
            'reason' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
