<?php

use App\Enums\EventStatus;
use App\Enums\TaskRecurrenceType;
use App\Models\ActivityLog;
use App\Models\RecurringSchoolClass;
use App\Models\SchoolClass;
use App\Models\SchoolClassException;
use App\Models\SchoolClassInstance;
use App\Models\User;
use App\Services\SchoolClassService;
use Carbon\Carbon;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->service = app(SchoolClassService::class);
});

test('create school class sets user_id and required attributes', function (): void {
    $start = Carbon::parse('2025-09-01 09:00');
    $end = Carbon::parse('2025-09-01 10:30');

    $class = $this->service->createSchoolClass($this->user, [
        'subject_name' => 'Biology',
        'teacher_name' => 'Dr. Lee',
        'start_datetime' => $start,
        'end_datetime' => $end,
    ]);

    expect($class)->toBeInstanceOf(SchoolClass::class)
        ->and($class->user_id)->toBe($this->user->id)
        ->and($class->subject_name)->toBe('Biology')
        ->and($class->teacher_name)->toBe('Dr. Lee')
        ->and($class->start_datetime->equalTo($start))->toBeTrue()
        ->and($class->end_datetime->equalTo($end))->toBeTrue()
        ->and($class->exists)->toBeTrue();

    expect(ActivityLog::query()->where('loggable_type', $class->getMorphClass())->where('loggable_id', $class->id)->count())->toBe(1);
});

test('create school class with recurrence enabled creates recurring school class', function (): void {
    $start = Carbon::parse('2025-02-01 09:00');
    $end = Carbon::parse('2025-06-30 15:00');

    $class = $this->service->createSchoolClass($this->user, [
        'subject_name' => 'Algebra',
        'teacher_name' => 'Dr. Smith',
        'start_datetime' => $start,
        'end_datetime' => $end,
        'recurrence' => [
            'enabled' => true,
            'type' => TaskRecurrenceType::Weekly->value,
            'interval' => 2,
            'daysOfWeek' => [1, 3],
        ],
    ]);

    $class->load('recurringSchoolClass');
    expect($class->recurringSchoolClass)->not->toBeNull()
        ->and($class->recurringSchoolClass->recurrence_type)->toBe(TaskRecurrenceType::Weekly)
        ->and($class->recurringSchoolClass->interval)->toBe(2)
        ->and(json_decode($class->recurringSchoolClass->days_of_week, true))->toEqual([1, 3]);
});

test('update school class updates attributes', function (): void {
    $class = SchoolClass::factory()->for($this->user)->create(['subject_name' => 'Original']);

    $updated = $this->service->updateSchoolClass($class, ['subject_name' => 'Updated']);

    expect($updated->subject_name)->toBe('Updated')
        ->and($class->fresh()->subject_name)->toBe('Updated');
});

test('update school class start and end datetime syncs to recurring school class', function (): void {
    $class = SchoolClass::factory()->for($this->user)->create([
        'start_datetime' => Carbon::parse('2025-01-01 08:00'),
        'end_datetime' => Carbon::parse('2025-01-01 09:00'),
    ]);
    RecurringSchoolClass::factory()->create([
        'school_class_id' => $class->id,
        'start_datetime' => $class->start_datetime,
        'end_datetime' => $class->end_datetime,
    ]);

    $newStart = Carbon::parse('2025-03-01 09:00');
    $newEnd = Carbon::parse('2025-03-31 17:00');
    $this->service->updateSchoolClass($class, [
        'start_datetime' => $newStart,
        'end_datetime' => $newEnd,
    ]);

    $recurring = $class->recurringSchoolClass()->first();
    expect($recurring)->not->toBeNull()
        ->and($recurring->start_datetime->format('Y-m-d H:i'))->toBe($newStart->format('Y-m-d H:i'))
        ->and($recurring->end_datetime->format('Y-m-d H:i'))->toBe($newEnd->format('Y-m-d H:i'));
});

test('delete school class soft deletes', function (): void {
    $class = SchoolClass::factory()->for($this->user)->create();
    $recurring = RecurringSchoolClass::factory()->create(['school_class_id' => $class->id]);

    $result = $this->service->deleteSchoolClass($class);

    expect($result)->toBeTrue();
    expect(SchoolClass::withTrashed()->find($class->id))->not->toBeNull()
        ->and(SchoolClass::withTrashed()->find($class->id)->trashed())->toBeTrue();
    expect(RecurringSchoolClass::query()->find($recurring->id))->not->toBeNull();
});

test('restore school class clears deleted_at', function (): void {
    $class = SchoolClass::factory()->for($this->user)->create();
    $class->delete();
    expect($class->trashed())->toBeTrue();

    $result = $this->service->restoreSchoolClass($class, $this->user);

    expect($result)->toBeTrue();
    expect(SchoolClass::query()->find($class->id))->not->toBeNull()
        ->and($class->fresh()->trashed())->toBeFalse();
});

test('force delete school class removes record permanently', function (): void {
    $class = SchoolClass::factory()->for($this->user)->create();
    $classId = $class->id;
    $class->delete();
    expect(SchoolClass::withTrashed()->find($classId))->not->toBeNull();

    $result = $this->service->forceDeleteSchoolClass($class->fresh(), $this->user);

    expect($result)->toBeTrue();
    expect(SchoolClass::withTrashed()->find($classId))->toBeNull();
});

test('force delete school class removes recurring school class', function (): void {
    $class = SchoolClass::factory()->for($this->user)->create();
    $recurring = RecurringSchoolClass::factory()->create(['school_class_id' => $class->id]);
    $recurringId = $recurring->id;
    $class->delete();

    $this->service->forceDeleteSchoolClass($class->fresh(), $this->user);

    expect(RecurringSchoolClass::query()->find($recurringId))->toBeNull();
});

test('update or create recurring school class creates when enabled with type', function (): void {
    $class = SchoolClass::factory()->for($this->user)->create([
        'start_datetime' => Carbon::parse('2025-02-01'),
        'end_datetime' => Carbon::parse('2025-02-28'),
    ]);

    $this->service->updateOrCreateRecurringSchoolClass($class, [
        'enabled' => true,
        'type' => TaskRecurrenceType::Daily->value,
        'interval' => 1,
        'daysOfWeek' => [],
    ]);

    $class->load('recurringSchoolClass');
    expect($class->recurringSchoolClass)->not->toBeNull()
        ->and($class->recurringSchoolClass->recurrence_type->value)->toBe('daily');
});

test('update or create recurring school class replaces existing when called again', function (): void {
    $class = SchoolClass::factory()->for($this->user)->create([
        'start_datetime' => Carbon::parse('2025-02-01'),
        'end_datetime' => Carbon::parse('2025-02-28'),
    ]);
    $this->service->updateOrCreateRecurringSchoolClass($class, [
        'enabled' => true,
        'type' => TaskRecurrenceType::Daily->value,
        'interval' => 1,
        'daysOfWeek' => [],
    ]);
    $class->load('recurringSchoolClass');
    $firstId = $class->recurringSchoolClass->id;

    $this->service->updateOrCreateRecurringSchoolClass($class, [
        'enabled' => true,
        'type' => TaskRecurrenceType::Weekly->value,
        'interval' => 1,
        'daysOfWeek' => [1],
    ]);

    $class->load('recurringSchoolClass');
    expect(RecurringSchoolClass::query()->find($firstId))->toBeNull()
        ->and($class->recurringSchoolClass->recurrence_type->value)->toBe('weekly');
});

test('update or create recurring school class disables by deleting when enabled false', function (): void {
    $class = SchoolClass::factory()->for($this->user)->create([
        'start_datetime' => Carbon::parse('2025-02-01'),
        'end_datetime' => Carbon::parse('2025-02-28'),
    ]);
    $this->service->updateOrCreateRecurringSchoolClass($class, [
        'enabled' => true,
        'type' => TaskRecurrenceType::Daily->value,
        'interval' => 1,
        'daysOfWeek' => [],
    ]);
    $class->load('recurringSchoolClass');
    $recurringId = $class->recurringSchoolClass->id;

    $this->service->updateOrCreateRecurringSchoolClass($class, ['enabled' => false, 'type' => null]);

    expect(RecurringSchoolClass::query()->find($recurringId))->toBeNull();
});

test('update recurring occurrence status creates school class instance', function (): void {
    $class = SchoolClass::factory()->for($this->user)->create();
    $recurring = RecurringSchoolClass::factory()->create([
        'school_class_id' => $class->id,
        'start_datetime' => Carbon::parse('2025-02-01'),
        'end_datetime' => Carbon::parse('2025-02-28'),
    ]);
    $date = Carbon::parse('2025-02-10');

    $instance = $this->service->updateRecurringOccurrenceStatus($class, $date, EventStatus::Completed);

    expect($instance)->toBeInstanceOf(SchoolClassInstance::class)
        ->and($instance->recurring_school_class_id)->toBe($recurring->id)
        ->and($instance->instance_date->format('Y-m-d'))->toBe('2025-02-10')
        ->and($instance->status)->toBe(EventStatus::Completed)
        ->and($instance->completed_at)->not->toBeNull();
});

test('update recurring occurrence status sets cancelled for cancelled status', function (): void {
    $class = SchoolClass::factory()->for($this->user)->create();
    RecurringSchoolClass::factory()->create(['school_class_id' => $class->id]);
    $date = Carbon::parse('2025-02-10');

    $instance = $this->service->updateRecurringOccurrenceStatus($class, $date, EventStatus::Cancelled);

    expect($instance->cancelled)->toBeTrue();
});

test('update recurring occurrence status updates existing instance', function (): void {
    $class = SchoolClass::factory()->for($this->user)->create();
    $recurring = RecurringSchoolClass::factory()->create(['school_class_id' => $class->id]);
    $date = Carbon::parse('2025-02-10');
    SchoolClassInstance::factory()->create([
        'recurring_school_class_id' => $recurring->id,
        'school_class_id' => $class->id,
        'instance_date' => $date,
        'status' => EventStatus::Scheduled,
    ]);

    $instance = $this->service->updateRecurringOccurrenceStatus($class, $date, EventStatus::Ongoing);

    expect($instance->status)->toBe(EventStatus::Ongoing);
    expect(SchoolClassInstance::query()->where('recurring_school_class_id', $recurring->id)->whereDate('instance_date', $date)->count())->toBe(1);
});

test('create school class exception creates or updates exception', function (): void {
    $class = SchoolClass::factory()->for($this->user)->create();
    $recurring = RecurringSchoolClass::factory()->create(['school_class_id' => $class->id]);
    $date = Carbon::parse('2025-02-10');

    $exception = $this->service->createSchoolClassException($recurring, $date, true, null, $this->user);

    expect($exception)->toBeInstanceOf(SchoolClassException::class)
        ->and($exception->recurring_school_class_id)->toBe($recurring->id)
        ->and($exception->exception_date->format('Y-m-d'))->toBe('2025-02-10')
        ->and($exception->is_deleted)->toBeTrue();
});

test('create school class exception stores reason when provided', function (): void {
    $class = SchoolClass::factory()->for($this->user)->create();
    $recurring = RecurringSchoolClass::factory()->create(['school_class_id' => $class->id]);
    $date = Carbon::parse('2025-02-10');

    $exception = $this->service->createSchoolClassException($recurring, $date, true, null, $this->user, 'Holiday');

    expect($exception->reason)->toBe('Holiday');
});

test('delete school class exception removes record and occurrence is included again in expansion', function (): void {
    $class = SchoolClass::factory()->for($this->user)->create();
    $recurring = RecurringSchoolClass::factory()->create([
        'school_class_id' => $class->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2025-02-01'),
        'end_datetime' => Carbon::parse('2025-02-28'),
    ]);
    $date = Carbon::parse('2025-02-10');
    $exception = $this->service->createSchoolClassException($recurring, $date, true, null, $this->user);

    $occurrencesBefore = $this->service->getOccurrencesForDateRange($recurring, $date, $date);
    expect($occurrencesBefore)->toBeEmpty();

    $result = $this->service->deleteSchoolClassException($exception);

    expect($result)->toBeTrue();
    expect(SchoolClassException::query()->find($exception->id))->toBeNull();

    $occurrencesAfter = $this->service->getOccurrencesForDateRange($recurring, $date, $date);
    expect($occurrencesAfter)->toHaveCount(1)
        ->and($occurrencesAfter[0]->format('Y-m-d'))->toBe('2025-02-10');
});

test('get exceptions for recurring school class returns all when no date range', function (): void {
    $class = SchoolClass::factory()->for($this->user)->create();
    $recurring = RecurringSchoolClass::factory()->create(['school_class_id' => $class->id]);
    SchoolClassException::factory()->create(['recurring_school_class_id' => $recurring->id, 'exception_date' => Carbon::parse('2025-02-01')]);
    SchoolClassException::factory()->create(['recurring_school_class_id' => $recurring->id, 'exception_date' => Carbon::parse('2025-02-15')]);

    $exceptions = $this->service->getExceptionsForRecurringSchoolClass($recurring);

    expect($exceptions)->toHaveCount(2)
        ->and($exceptions->pluck('exception_date')->map(fn ($d) => $d->format('Y-m-d'))->all())
        ->toEqual(['2025-02-01', '2025-02-15']);
});

test('get exceptions for recurring school class filters by date range when provided', function (): void {
    $class = SchoolClass::factory()->for($this->user)->create();
    $recurring = RecurringSchoolClass::factory()->create(['school_class_id' => $class->id]);
    SchoolClassException::factory()->create(['recurring_school_class_id' => $recurring->id, 'exception_date' => Carbon::parse('2025-02-01')]);
    SchoolClassException::factory()->create(['recurring_school_class_id' => $recurring->id, 'exception_date' => Carbon::parse('2025-02-10')]);
    SchoolClassException::factory()->create(['recurring_school_class_id' => $recurring->id, 'exception_date' => Carbon::parse('2025-02-20')]);

    $exceptions = $this->service->getExceptionsForRecurringSchoolClass(
        $recurring,
        Carbon::parse('2025-02-05'),
        Carbon::parse('2025-02-15')
    );

    expect($exceptions)->toHaveCount(1)
        ->and($exceptions->first()->exception_date->format('Y-m-d'))->toBe('2025-02-10');
});

test('update school class exception updates allowed attributes', function (): void {
    $class = SchoolClass::factory()->for($this->user)->create();
    $recurring = RecurringSchoolClass::factory()->create(['school_class_id' => $class->id]);
    $exception = SchoolClassException::factory()->create([
        'recurring_school_class_id' => $recurring->id,
        'exception_date' => Carbon::parse('2025-02-10'),
        'is_deleted' => true,
        'reason' => null,
    ]);

    $updated = $this->service->updateSchoolClassException($exception, [
        'is_deleted' => false,
        'reason' => 'Rescheduled',
    ]);

    expect($updated->is_deleted)->toBeFalse()
        ->and($updated->reason)->toBe('Rescheduled');
});

test('update school class exception ignores non allowed attributes', function (): void {
    $class = SchoolClass::factory()->for($this->user)->create();
    $recurring = RecurringSchoolClass::factory()->create(['school_class_id' => $class->id]);
    $exception = SchoolClassException::factory()->create([
        'recurring_school_class_id' => $recurring->id,
        'exception_date' => Carbon::parse('2025-02-10'),
        'reason' => 'Original',
    ]);

    $this->service->updateSchoolClassException($exception, [
        'exception_date' => '2025-03-01',
        'recurring_school_class_id' => 999,
    ]);

    $exception->refresh();
    expect($exception->exception_date->format('Y-m-d'))->toBe('2025-02-10')
        ->and($exception->recurring_school_class_id)->toBe($recurring->id)
        ->and($exception->reason)->toBe('Original');
});
