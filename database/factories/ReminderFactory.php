<?php

namespace Database\Factories;

use App\Enums\ReminderStatus;
use App\Enums\ReminderType;
use App\Models\Event;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reminder>
 */
class ReminderFactory extends Factory
{
    protected $model = Reminder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'remindable_type' => (new Task)->getMorphClass(),
            'remindable_id' => Task::factory(),
            'type' => ReminderType::TaskDueSoon,
            'scheduled_at' => now()->addHour(),
            'status' => ReminderStatus::Pending,
            'sent_at' => null,
            'cancelled_at' => null,
            'snoozed_until' => null,
            'payload' => [
                'task_id' => 0,
                'task_title' => '',
                'due_at' => null,
                'offset_minutes' => 60,
            ],
        ];
    }

    public function forUserTask(User $user, Task $task, ReminderType $type = ReminderType::TaskDueSoon): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user->id,
            'remindable_type' => $task->getMorphClass(),
            'remindable_id' => $task->id,
            'type' => $type,
            'scheduled_at' => now()->addHours(2),
            'status' => ReminderStatus::Pending,
            'sent_at' => null,
            'cancelled_at' => null,
            'snoozed_until' => null,
            'payload' => match ($type) {
                ReminderType::TaskDueSoon => [
                    'task_id' => $task->id,
                    'task_title' => (string) $task->title,
                    'due_at' => $task->end_datetime?->toIso8601String(),
                    'offset_minutes' => 60,
                ],
                ReminderType::TaskOverdue => [
                    'task_id' => $task->id,
                    'task_title' => (string) $task->title,
                    'due_at' => $task->end_datetime?->toIso8601String(),
                ],
                default => [],
            },
        ]);
    }

    public function forUserEvent(User $user, Event $event): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user->id,
            'remindable_type' => $event->getMorphClass(),
            'remindable_id' => $event->id,
            'type' => ReminderType::EventStartSoon,
            'scheduled_at' => now()->addHours(3),
            'status' => ReminderStatus::Pending,
            'sent_at' => null,
            'cancelled_at' => null,
            'snoozed_until' => null,
            'payload' => [
                'event_id' => $event->id,
                'event_title' => (string) $event->title,
                'start_at' => $event->start_datetime?->toIso8601String(),
                'offset_minutes' => 15,
            ],
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => ReminderStatus::Pending,
            'sent_at' => null,
            'cancelled_at' => null,
        ]);
    }

    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => ReminderStatus::Sent,
            'sent_at' => now()->subMinutes(5),
            'scheduled_at' => now()->subHour(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => ReminderStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
