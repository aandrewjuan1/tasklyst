<?php

namespace App\Services\Reminders;

use App\Actions\Reminders\CancelPendingRemindersForRemindableAction;
use App\Enums\EventStatus;
use App\Enums\ReminderStatus;
use App\Enums\ReminderType;
use App\Models\Event;
use App\Models\Reminder;
use App\Models\SchoolClass;
use App\Models\Task;
use App\Services\RecurrenceExpander;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class ReminderSchedulerService
{
    public function __construct(
        private CancelPendingRemindersForRemindableAction $cancelPendingRemindersForRemindable,
        private RecurrenceExpander $recurrenceExpander,
    ) {}

    public function syncTaskReminders(Task $task): void
    {
        $task->refresh();

        if ($task->trashed() || $task->completed_at !== null) {
            $this->cancelForRemindable($task);

            return;
        }

        $this->syncTaskDueSoonReminders($task);
        $this->syncTaskOverdueReminder($task);
    }

    public function syncEventReminders(Event $event): void
    {
        $event->refresh();

        if ($event->trashed() || in_array($event->status?->value, [EventStatus::Cancelled->value, EventStatus::Completed->value], true)) {
            $this->cancelForRemindable($event);

            return;
        }

        $this->syncEventStartSoonReminders($event);
    }

    public function syncSchoolClassReminders(SchoolClass $schoolClass): void
    {
        $schoolClass->refresh();
        $schoolClass->loadMissing('recurringSchoolClass');

        if ($schoolClass->trashed()) {
            $this->cancelForRemindable($schoolClass);

            return;
        }

        [$startsAt, $endsAt] = $this->resolveNextSchoolClassWindow($schoolClass);
        if ($startsAt === null || $endsAt === null) {
            $this->cancelForRemindable($schoolClass);

            return;
        }

        $this->syncSchoolClassStartSoonReminders($schoolClass, $startsAt, $endsAt);
        $this->syncSchoolClassNowLiveReminder($schoolClass, $startsAt, $endsAt);
        $this->syncSchoolClassEndingSoonReminders($schoolClass, $startsAt, $endsAt);
        $this->syncSchoolClassMissedReminder($schoolClass, $startsAt, $endsAt);
    }

    public function cancelForRemindable(Model $model, ?ReminderType $type = null): void
    {
        $this->cancelPendingRemindersForRemindable->execute($model, $type);
    }

    private function syncTaskDueSoonReminders(Task $task): void
    {
        $this->cancelForRemindable($task, ReminderType::TaskDueSoon);

        $dueAt = $task->end_datetime;
        if (! $dueAt instanceof CarbonInterface) {
            return;
        }
        $dueAt = CarbonImmutable::instance($dueAt);

        $offsets = config('reminders.task_due_soon_offsets_minutes', []);
        if (! is_array($offsets) || $offsets === []) {
            return;
        }

        $created = false;
        $smallestPositiveOffset = null;

        foreach ($offsets as $offsetMinutes) {
            $minutes = (int) $offsetMinutes;
            if ($minutes <= 0) {
                continue;
            }

            $smallestPositiveOffset = $smallestPositiveOffset === null
                ? $minutes
                : min($smallestPositiveOffset, $minutes);

            $scheduledAt = $dueAt->subMinutes($minutes);
            if ($scheduledAt->lte(now())) {
                continue;
            }

            Reminder::query()->create([
                'user_id' => $task->user_id,
                'remindable_type' => $task->getMorphClass(),
                'remindable_id' => $task->getKey(),
                'type' => ReminderType::TaskDueSoon,
                'scheduled_at' => $scheduledAt,
                'status' => ReminderStatus::Pending,
                'payload' => [
                    'task_id' => $task->id,
                    'task_title' => (string) $task->title,
                    'due_at' => $dueAt->toIso8601String(),
                    'offset_minutes' => $minutes,
                ],
            ]);
            $created = true;
        }

        // If no configured offset remains in the future (e.g. due in 30m with a 60m offset),
        // create one immediate due-soon reminder so users still get a useful heads-up.
        if (! $created && $dueAt->gt(now())) {
            $minutesUntilDue = max(1, (int) now()->diffInMinutes($dueAt, false));

            Reminder::query()->create([
                'user_id' => $task->user_id,
                'remindable_type' => $task->getMorphClass(),
                'remindable_id' => $task->getKey(),
                'type' => ReminderType::TaskDueSoon,
                'scheduled_at' => now(),
                'status' => ReminderStatus::Pending,
                'payload' => [
                    'task_id' => $task->id,
                    'task_title' => (string) $task->title,
                    'due_at' => $dueAt->toIso8601String(),
                    'offset_minutes' => $smallestPositiveOffset ?? $minutesUntilDue,
                    'fallback_immediate' => true,
                ],
            ]);
        }
    }

    private function syncTaskOverdueReminder(Task $task): void
    {
        $this->cancelForRemindable($task, ReminderType::TaskOverdue);

        $dueAt = $task->end_datetime;
        if (! $dueAt instanceof CarbonInterface) {
            return;
        }
        $dueAt = CarbonImmutable::instance($dueAt);

        if ($dueAt->lte(now())) {
            // Due already passed; dispatch path can decide whether to send immediately.
            // We still schedule at due time for deterministic behavior.
        }

        Reminder::query()->create([
            'user_id' => $task->user_id,
            'remindable_type' => $task->getMorphClass(),
            'remindable_id' => $task->getKey(),
            'type' => ReminderType::TaskOverdue,
            'scheduled_at' => $dueAt,
            'status' => ReminderStatus::Pending,
            'payload' => [
                'task_id' => $task->id,
                'task_title' => (string) $task->title,
                'due_at' => $dueAt->toIso8601String(),
            ],
        ]);
    }

    private function syncEventStartSoonReminders(Event $event): void
    {
        $this->cancelForRemindable($event, ReminderType::EventStartSoon);

        $startAt = $event->start_datetime;
        if (! $startAt instanceof CarbonInterface) {
            return;
        }
        $startAt = CarbonImmutable::instance($startAt);

        $offsets = config('reminders.event_start_soon_offsets_minutes', []);
        if (! is_array($offsets) || $offsets === []) {
            return;
        }

        foreach ($offsets as $offsetMinutes) {
            $minutes = (int) $offsetMinutes;
            if ($minutes <= 0) {
                continue;
            }

            $scheduledAt = $startAt->subMinutes($minutes);
            if ($scheduledAt->lte(now())) {
                continue;
            }

            Reminder::query()->create([
                'user_id' => $event->user_id,
                'remindable_type' => $event->getMorphClass(),
                'remindable_id' => $event->getKey(),
                'type' => ReminderType::EventStartSoon,
                'scheduled_at' => $scheduledAt,
                'status' => ReminderStatus::Pending,
                'payload' => [
                    'event_id' => $event->id,
                    'event_title' => (string) $event->title,
                    'start_at' => $startAt->toIso8601String(),
                    'offset_minutes' => $minutes,
                ],
            ]);
        }
    }

    private function syncSchoolClassStartSoonReminders(SchoolClass $schoolClass, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        $this->cancelForRemindable($schoolClass, ReminderType::SchoolClassStartSoon);

        $offsets = config('reminders.school_class_start_soon_offsets_minutes', []);
        if (! is_array($offsets) || $offsets === []) {
            return;
        }

        $created = false;
        $smallestPositiveOffset = null;

        foreach ($offsets as $offsetMinutes) {
            $minutes = (int) $offsetMinutes;
            if ($minutes <= 0) {
                continue;
            }

            $smallestPositiveOffset = $smallestPositiveOffset === null
                ? $minutes
                : min($smallestPositiveOffset, $minutes);

            $scheduledAt = $startsAt->subMinutes($minutes);
            if ($scheduledAt->lte(now())) {
                continue;
            }

            Reminder::query()->create([
                'user_id' => $schoolClass->user_id,
                'remindable_type' => $schoolClass->getMorphClass(),
                'remindable_id' => $schoolClass->getKey(),
                'type' => ReminderType::SchoolClassStartSoon,
                'scheduled_at' => $scheduledAt,
                'status' => ReminderStatus::Pending,
                'payload' => [
                    'school_class_id' => $schoolClass->id,
                    'subject_name' => (string) $schoolClass->subject_name,
                    'starts_at' => $startsAt->toIso8601String(),
                    'ends_at' => $endsAt->toIso8601String(),
                    'offset_minutes' => $minutes,
                ],
            ]);
            $created = true;
        }

        if (! $created && $startsAt->gt(now())) {
            $minutesUntilStart = max(1, (int) now()->diffInMinutes($startsAt, false));
            Reminder::query()->create([
                'user_id' => $schoolClass->user_id,
                'remindable_type' => $schoolClass->getMorphClass(),
                'remindable_id' => $schoolClass->getKey(),
                'type' => ReminderType::SchoolClassStartSoon,
                'scheduled_at' => now(),
                'status' => ReminderStatus::Pending,
                'payload' => [
                    'school_class_id' => $schoolClass->id,
                    'subject_name' => (string) $schoolClass->subject_name,
                    'starts_at' => $startsAt->toIso8601String(),
                    'ends_at' => $endsAt->toIso8601String(),
                    'offset_minutes' => $smallestPositiveOffset ?? $minutesUntilStart,
                    'fallback_immediate' => true,
                ],
            ]);
        }
    }

    private function syncSchoolClassNowLiveReminder(SchoolClass $schoolClass, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        $this->cancelForRemindable($schoolClass, ReminderType::SchoolClassNowLive);

        Reminder::query()->create([
            'user_id' => $schoolClass->user_id,
            'remindable_type' => $schoolClass->getMorphClass(),
            'remindable_id' => $schoolClass->getKey(),
            'type' => ReminderType::SchoolClassNowLive,
            'scheduled_at' => $startsAt,
            'status' => ReminderStatus::Pending,
            'payload' => [
                'school_class_id' => $schoolClass->id,
                'subject_name' => (string) $schoolClass->subject_name,
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $endsAt->toIso8601String(),
            ],
        ]);
    }

    private function syncSchoolClassEndingSoonReminders(SchoolClass $schoolClass, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        $this->cancelForRemindable($schoolClass, ReminderType::SchoolClassEndingSoon);

        $offsets = config('reminders.school_class_ending_soon_offsets_minutes', []);
        if (! is_array($offsets) || $offsets === []) {
            return;
        }

        foreach ($offsets as $offsetMinutes) {
            $minutes = (int) $offsetMinutes;
            if ($minutes <= 0) {
                continue;
            }

            $scheduledAt = $endsAt->subMinutes($minutes);
            if ($scheduledAt->lte(now())) {
                continue;
            }

            Reminder::query()->create([
                'user_id' => $schoolClass->user_id,
                'remindable_type' => $schoolClass->getMorphClass(),
                'remindable_id' => $schoolClass->getKey(),
                'type' => ReminderType::SchoolClassEndingSoon,
                'scheduled_at' => $scheduledAt,
                'status' => ReminderStatus::Pending,
                'payload' => [
                    'school_class_id' => $schoolClass->id,
                    'subject_name' => (string) $schoolClass->subject_name,
                    'starts_at' => $startsAt->toIso8601String(),
                    'ends_at' => $endsAt->toIso8601String(),
                    'offset_minutes' => $minutes,
                ],
            ]);
        }
    }

    private function syncSchoolClassMissedReminder(SchoolClass $schoolClass, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        $this->cancelForRemindable($schoolClass, ReminderType::SchoolClassMissed);

        Reminder::query()->create([
            'user_id' => $schoolClass->user_id,
            'remindable_type' => $schoolClass->getMorphClass(),
            'remindable_id' => $schoolClass->getKey(),
            'type' => ReminderType::SchoolClassMissed,
            'scheduled_at' => $endsAt,
            'status' => ReminderStatus::Pending,
            'payload' => [
                'school_class_id' => $schoolClass->id,
                'subject_name' => (string) $schoolClass->subject_name,
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $endsAt->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private function resolveNextSchoolClassWindow(SchoolClass $schoolClass): array
    {
        if ($schoolClass->start_time === null || $schoolClass->end_time === null) {
            return [null, null];
        }

        $anchorDate = null;
        $recurring = $schoolClass->recurringSchoolClass;

        if ($recurring !== null) {
            $rangeStart = CarbonImmutable::now()->startOfDay();
            $rangeEnd = $recurring->end_datetime !== null
                ? CarbonImmutable::instance($recurring->end_datetime)->endOfDay()
                : CarbonImmutable::now()->addMonths(6)->endOfDay();

            $occurrences = $this->recurrenceExpander->expand($recurring, $rangeStart, $rangeEnd);
            $anchorDate = collect($occurrences)
                ->map(fn (CarbonInterface $date) => CarbonImmutable::instance($date)->startOfDay())
                ->first(fn (CarbonImmutable $date) => $date->greaterThanOrEqualTo(CarbonImmutable::now()->startOfDay()));
        } elseif ($schoolClass->start_datetime !== null) {
            $anchorDate = CarbonImmutable::instance($schoolClass->start_datetime)->startOfDay();
        }

        if (! $anchorDate instanceof CarbonImmutable) {
            return [null, null];
        }

        try {
            $startsAt = CarbonImmutable::parse($anchorDate->toDateString().' '.$schoolClass->start_time);
            $endsAt = CarbonImmutable::parse($anchorDate->toDateString().' '.$schoolClass->end_time);
        } catch (\Throwable) {
            return [null, null];
        }

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            $endsAt = $endsAt->addDay();
        }

        return [$startsAt, $endsAt];
    }
}
