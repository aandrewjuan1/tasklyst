<?php

namespace App\Services;

use App\Enums\ActivityLogAction;
use App\Enums\EventStatus;
use App\Enums\TaskRecurrenceType;
use App\Models\RecurringSchoolClass;
use App\Models\SchoolClass;
use App\Models\SchoolClassException;
use App\Models\SchoolClassInstance;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Reminders\ReminderDispatcherService;
use App\Services\Reminders\ReminderSchedulerService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SchoolClassService
{
    public function __construct(
        private ActivityLogRecorder $activityLogRecorder,
        private RecurrenceExpander $recurrenceExpander,
        private ReminderSchedulerService $reminderSchedulerService,
        private ReminderDispatcherService $reminderDispatcherService,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createSchoolClass(User $user, array $attributes): SchoolClass
    {
        return DB::transaction(function () use ($user, $attributes): SchoolClass {
            $recurrenceData = $attributes['recurrence'] ?? null;
            $seriesEndCap = $attributes['recurrence_series_end_datetime'] ?? null;
            unset($attributes['recurrence'], $attributes['recurrence_series_end_datetime']);

            $this->assignTeacherFromNameInput($user->id, $attributes);
            $this->ensureRequiredTimes($attributes);

            $schoolClass = SchoolClass::query()->create([
                ...$attributes,
                'user_id' => $user->id,
            ]);

            if ($recurrenceData !== null && ($recurrenceData['enabled'] ?? false)) {
                $this->createRecurringSchoolClass($schoolClass, $recurrenceData, $seriesEndCap);
            }

            $this->activityLogRecorder->record($schoolClass, $user, ActivityLogAction::ItemCreated, [
                'subject_name' => $schoolClass->subject_name,
            ]);

            $this->reminderSchedulerService->syncSchoolClassReminders($schoolClass);
            $this->reminderDispatcherService->queueProcessDueForRemindable($schoolClass);

            return $schoolClass;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateSchoolClass(SchoolClass $schoolClass, array $attributes): SchoolClass
    {
        unset($attributes['user_id']);

        $this->assignTeacherFromNameInput((int) $schoolClass->user_id, $attributes);
        $this->normalizeTimeInputs($attributes);

        return DB::transaction(function () use ($schoolClass, $attributes): SchoolClass {
            $schoolClass->fill($attributes);
            $schoolClass->save();

            $this->syncRecurringSchoolClassDatesIfNeeded($schoolClass, $attributes);
            $this->reminderSchedulerService->syncSchoolClassReminders($schoolClass);
            $this->reminderDispatcherService->queueProcessDueForRemindable($schoolClass);

            return $schoolClass->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $recurrenceData
     */
    public function updateOrCreateRecurringSchoolClass(SchoolClass $schoolClass, array $recurrenceData): void
    {
        DB::transaction(function () use ($schoolClass, $recurrenceData): void {
            $preservedSeriesEnd = $schoolClass->recurringSchoolClass?->end_datetime;

            $schoolClass->recurringSchoolClass?->delete();

            if (($recurrenceData['enabled'] ?? false) && ($recurrenceData['type'] ?? null) !== null) {
                $this->createRecurringSchoolClass($schoolClass, $recurrenceData, $preservedSeriesEnd);
            }

            $this->reminderSchedulerService->syncSchoolClassReminders($schoolClass);
            $this->reminderDispatcherService->queueProcessDueForRemindable($schoolClass);
        });
    }

    /**
     * @param  array<string, mixed>  $recurrenceData
     */
    private function createRecurringSchoolClass(SchoolClass $schoolClass, array $recurrenceData, mixed $recurringSeriesEndCap = null): void
    {
        $recurrenceType = $recurrenceData['type'] ?? null;
        if ($recurrenceType === null) {
            return;
        }

        $recurrenceTypeEnum = TaskRecurrenceType::from($recurrenceType);
        $interval = max(1, (int) ($recurrenceData['interval'] ?? 1));
        $daysOfWeek = $recurrenceData['daysOfWeek'] ?? [];

        $startDatetime = $schoolClass->start_datetime;
        $endForRecurringRow = $recurringSeriesEndCap !== null
            ? Carbon::parse($recurringSeriesEndCap)
            : $schoolClass->end_datetime;

        $daysOfWeekString = null;
        if (is_array($daysOfWeek) && ! empty($daysOfWeek)) {
            $daysOfWeekString = json_encode($daysOfWeek, JSON_THROW_ON_ERROR);
        }

        RecurringSchoolClass::query()->create([
            'school_class_id' => $schoolClass->id,
            'recurrence_type' => $recurrenceTypeEnum,
            'interval' => $interval,
            'days_of_week' => $daysOfWeekString,
            'start_datetime' => $startDatetime,
            'end_datetime' => $endForRecurringRow,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function syncRecurringSchoolClassDatesIfNeeded(SchoolClass $schoolClass, array $attributes): void
    {
        $dateKeys = ['start_datetime', 'end_datetime'];
        $hasDateChanges = array_intersect(array_keys($attributes), $dateKeys) !== [];

        if (! $hasDateChanges) {
            return;
        }

        $recurring = $schoolClass->recurringSchoolClass ?? RecurringSchoolClass::query()->where('school_class_id', $schoolClass->id)->first();
        if ($recurring === null) {
            return;
        }

        $syncAttributes = [];
        if (array_key_exists('start_datetime', $attributes)) {
            $syncAttributes['start_datetime'] = $attributes['start_datetime'];
        }

        if ($syncAttributes !== []) {
            $recurring->update($syncAttributes);
        }
    }

    public function deleteSchoolClass(SchoolClass $schoolClass, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($schoolClass, $actor): bool {
            $this->activityLogRecorder->record($schoolClass, $actor, ActivityLogAction::ItemDeleted, [
                'subject_name' => $schoolClass->subject_name,
            ]);

            $deleted = (bool) $schoolClass->delete();
            $this->reminderSchedulerService->cancelForRemindable($schoolClass);

            return $deleted;
        });
    }

    public function restoreSchoolClass(SchoolClass $schoolClass, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($schoolClass, $actor): bool {
            $this->activityLogRecorder->record($schoolClass, $actor, ActivityLogAction::ItemRestored, [
                'subject_name' => $schoolClass->subject_name,
            ]);

            $restored = (bool) $schoolClass->restore();

            if ($restored) {
                $this->reminderSchedulerService->syncSchoolClassReminders($schoolClass);
                $this->reminderDispatcherService->queueProcessDueForRemindable($schoolClass);
            }

            return $restored;
        });
    }

    public function forceDeleteSchoolClass(SchoolClass $schoolClass, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($schoolClass, $actor): bool {
            $this->activityLogRecorder->record($schoolClass, $actor, ActivityLogAction::ItemDeleted, [
                'subject_name' => $schoolClass->subject_name,
            ]);

            $this->reminderSchedulerService->cancelForRemindable($schoolClass);

            return (bool) $schoolClass->forceDelete();
        });
    }

    /**
     * Create or update a SchoolClassInstance for the given occurrence date.
     */
    public function updateRecurringOccurrenceStatus(SchoolClass $schoolClass, CarbonInterface $date, EventStatus $status): SchoolClassInstance
    {
        $recurring = $schoolClass->recurringSchoolClass;
        if ($recurring === null) {
            $recurring = RecurringSchoolClass::query()->where('school_class_id', $schoolClass->id)->first();
        }
        if ($recurring === null) {
            throw new \InvalidArgumentException('School class must have a recurring row to update an occurrence status.');
        }

        $instanceDate = $date instanceof \DateTimeInterface
            ? $date->format('Y-m-d')
            : \Carbon\Carbon::parse($date)->format('Y-m-d');

        $instance = SchoolClassInstance::query()
            ->where('recurring_school_class_id', $recurring->id)
            ->whereDate('instance_date', $instanceDate)
            ->first();

        $attributes = [
            'school_class_id' => $schoolClass->id,
            'status' => $status,
            'cancelled' => $status === EventStatus::Cancelled,
            'completed_at' => $status === EventStatus::Completed ? now() : null,
        ];

        if ($instance !== null) {
            $instance->update($attributes);

            return $instance->fresh();
        }

        return SchoolClassInstance::query()->create([
            'recurring_school_class_id' => $recurring->id,
            'school_class_id' => $schoolClass->id,
            'instance_date' => $instanceDate,
            'status' => $status,
            'cancelled' => $status === EventStatus::Cancelled,
            'completed_at' => $status === EventStatus::Completed ? now() : null,
        ]);
    }

    /**
     * @return array<CarbonInterface>
     */
    public function getOccurrencesForDateRange(RecurringSchoolClass $recurring, CarbonInterface $start, CarbonInterface $end): array
    {
        return $this->recurrenceExpander->expand($recurring, $start, $end);
    }

    /**
     * @param  iterable<RecurringSchoolClass>  $recurringSchoolClasses
     * @return array<int>
     */
    public function getRelevantRecurringSchoolClassIdsForDate(iterable $recurringSchoolClasses, CarbonInterface $date): array
    {
        $ids = $this->recurrenceExpander->getRelevantRecurringIdsForDate(
            [],
            [],
            $date,
            $recurringSchoolClasses
        )['recurring_school_class_ids'] ?? [];

        $ids = array_values(array_map('intval', $ids));
        if ($ids === []) {
            return [];
        }

        $instanceDate = $date->format('Y-m-d');
        $cancelledRecurringIds = SchoolClassInstance::query()
            ->whereIn('recurring_school_class_id', $ids)
            ->whereDate('instance_date', $instanceDate)
            ->where(function ($query): void {
                $query->where('cancelled', true)
                    ->orWhere('status', EventStatus::Cancelled->value);
            })
            ->pluck('recurring_school_class_id')
            ->map(static fn ($value): int => (int) $value)
            ->all();

        if ($cancelledRecurringIds !== []) {
            $cancelledLookup = array_flip($cancelledRecurringIds);
            $ids = array_values(array_filter(
                $ids,
                static fn (int $id): bool => ! isset($cancelledLookup[$id])
            ));
        }

        return $ids;
    }

    /**
     * Non-recurring classes: overlap with the calendar day. Recurring: occurrence on {@see $dayStart} (via recurrence expander).
     *
     * @param  Collection<int, SchoolClass>  $classes
     * @return Collection<int, SchoolClass>
     */
    public function filterSchoolClassesForCalendarDay(Collection $classes, CarbonInterface $dayStart, CarbonInterface $dayEnd): Collection
    {
        $nonRecurring = $classes->filter(fn (SchoolClass $class): bool => $class->recurringSchoolClass === null);
        $recurringClasses = $classes->filter(fn (SchoolClass $class): bool => $class->recurringSchoolClass !== null);

        $relevantRecurringIds = $this->getRelevantRecurringSchoolClassIdsForDate(
            $recurringClasses->map(fn (SchoolClass $class) => $class->recurringSchoolClass)->filter(),
            $dayStart
        );
        $relevantRecurringLookup = array_flip($relevantRecurringIds);

        return $classes
            ->filter(function (SchoolClass $class) use ($dayStart, $dayEnd, $relevantRecurringLookup): bool {
                if ($class->recurringSchoolClass === null) {
                    return $this->nonRecurringSchoolClassOverlapsDay($class, $dayStart, $dayEnd);
                }

                return isset($relevantRecurringLookup[(int) $class->recurringSchoolClass->id]);
            })
            ->values();
    }

    /**
     * @param  Collection<int, SchoolClass>  $classes
     */
    public function countSchoolClassesOnCalendarDay(Collection $classes, CarbonInterface $dayStart, CarbonInterface $dayEnd): int
    {
        return $this->filterSchoolClassesForCalendarDay($classes, $dayStart, $dayEnd)->count();
    }

    private function nonRecurringSchoolClassOverlapsDay(SchoolClass $class, CarbonInterface $dayStart, CarbonInterface $dayEnd): bool
    {
        $start = $class->start_datetime;
        $end = $class->end_datetime;
        if ($start === null || $end === null) {
            return false;
        }

        return $start->lte($dayEnd) && $end->gte($dayStart);
    }

    public function createSchoolClassException(
        RecurringSchoolClass $recurringSchoolClass,
        CarbonInterface $date,
        bool $isDeleted,
        ?SchoolClassInstance $replacement = null,
        ?User $createdBy = null,
        ?string $reason = null
    ): SchoolClassException {
        $exceptionDate = $date instanceof \DateTimeInterface
            ? $date->format('Y-m-d')
            : \Carbon\Carbon::parse($date)->format('Y-m-d');

        return SchoolClassException::query()->updateOrCreate(
            [
                'recurring_school_class_id' => $recurringSchoolClass->id,
                'exception_date' => $exceptionDate,
            ],
            [
                'is_deleted' => $isDeleted,
                'replacement_instance_id' => $replacement?->id,
                'created_by' => $createdBy?->id,
                'reason' => $reason,
            ]
        );
    }

    public function deleteSchoolClassException(SchoolClassException $exception): bool
    {
        return (bool) $exception->delete();
    }

    /**
     * @return Collection<int, SchoolClassException>
     */
    public function getExceptionsForRecurringSchoolClass(
        RecurringSchoolClass $recurring,
        ?CarbonInterface $start = null,
        ?CarbonInterface $end = null
    ): Collection {
        $query = $recurring->schoolClassExceptions();

        if ($start !== null) {
            $query->whereDate('exception_date', '>=', $start);
        }
        if ($end !== null) {
            $query->whereDate('exception_date', '<=', $end);
        }

        return $query->orderBy('exception_date')->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateSchoolClassException(SchoolClassException $exception, array $attributes): SchoolClassException
    {
        $allowed = ['is_deleted', 'reason', 'replacement_instance_id'];
        $filtered = array_intersect_key($attributes, array_flip($allowed));

        if ($filtered !== []) {
            $exception->fill($filtered);
            $exception->save();
        }

        return $exception->fresh();
    }

    /**
     * Replace `teacher_name` input with `teacher_id` for persistence.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assignTeacherFromNameInput(int $userId, array &$attributes): void
    {
        if (! array_key_exists('teacher_name', $attributes)) {
            return;
        }

        $displayName = trim((string) $attributes['teacher_name']);
        unset($attributes['teacher_name']);

        if ($displayName === '') {
            throw new \InvalidArgumentException(__('Teacher name cannot be empty.'));
        }

        $teacher = Teacher::firstOrCreateByDisplayName($userId, $displayName);
        $attributes['teacher_id'] = $teacher->id;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ensureRequiredTimes(array &$attributes): void
    {
        $this->normalizeTimeInputs($attributes);

        if (! isset($attributes['start_time']) || ! isset($attributes['end_time'])) {
            throw new \InvalidArgumentException(__('Start and end times are required.'));
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function normalizeTimeInputs(array &$attributes): void
    {
        foreach (['start_time', 'end_time'] as $column) {
            if (! array_key_exists($column, $attributes)) {
                continue;
            }

            $value = $attributes[$column];
            if ($value === null || $value === '') {
                unset($attributes[$column]);

                continue;
            }

            $attributes[$column] = Carbon::parse((string) $value)->format('H:i:s');
        }
    }
}
