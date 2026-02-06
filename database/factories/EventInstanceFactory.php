<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Models\RecurringEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EventInstance>
 */
class EventInstanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recurring_event_id' => RecurringEvent::factory(),
            'event_id' => null,
            'instance_date' => fake()->dateTimeBetween('-1 week', '+1 week')->format('Y-m-d'),
            'status' => EventStatus::Scheduled,
            'cancelled' => false,
            'completed_at' => null,
        ];
    }
}
