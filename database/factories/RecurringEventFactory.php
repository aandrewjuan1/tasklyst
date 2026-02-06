<?php

namespace Database\Factories;

use App\Enums\EventRecurrenceType;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RecurringEvent>
 */
class RecurringEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDatetime = fake()->dateTimeBetween('-1 month', '+1 month');

        return [
            'event_id' => Event::factory(),
            'recurrence_type' => EventRecurrenceType::Daily,
            'interval' => 1,
            'days_of_week' => null,
            'start_datetime' => $startDatetime,
            'end_datetime' => fake()->optional(0.3)->dateTimeBetween($startDatetime, '+3 months'),
        ];
    }
}
