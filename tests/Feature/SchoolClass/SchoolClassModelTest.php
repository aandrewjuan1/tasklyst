<?php

use App\Enums\TaskRecurrenceType;
use App\Models\RecurringSchoolClass;
use App\Models\SchoolClass;
use App\Models\SchoolClassException;
use App\Models\SchoolClassInstance;
use App\Models\User;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('user has many school classes', function (): void {
    $schoolClass = SchoolClass::factory()->for($this->owner)->create();

    expect($this->owner->schoolClasses)->toHaveCount(1)
        ->and($this->owner->schoolClasses->first()->id)->toBe($schoolClass->id);
});

test('scope not archived excludes soft deleted school classes', function (): void {
    $active = SchoolClass::factory()->for($this->owner)->create(['subject_name' => 'Active']);
    $deleted = SchoolClass::factory()->for($this->owner)->create(['subject_name' => 'Deleted']);
    $deleted->delete();

    $classes = SchoolClass::query()->forUser($this->owner->id)->notArchived()->get();

    expect($classes)->toHaveCount(1)
        ->and($classes->first()->id)->toBe($active->id);
});

test('toast payload for create success has expected shape', function (): void {
    $payload = SchoolClass::toastPayload('create', true, 'Biology');

    expect($payload)->toHaveKeys(['type', 'message', 'icon'])
        ->and($payload['type'])->toBe('success')
        ->and($payload['icon'])->toBe('plus-circle');
});

test('property to column maps camelCase fields', function (): void {
    expect(SchoolClass::propertyToColumn('subjectName'))->toBe('subject_name')
        ->and(SchoolClass::propertyToColumn('teacherName'))->toBe('teacher_name');
});

test('scope for user returns only classes owned by the user', function (): void {
    $owned = SchoolClass::factory()->for($this->owner)->create(['subject_name' => 'Algebra']);
    SchoolClass::factory()->for($this->otherUser)->create(['subject_name' => 'Biology']);

    $classes = SchoolClass::query()->forUser($this->owner->id)->get();

    expect($classes)->toHaveCount(1)
        ->and($classes->first()->id)->toBe($owned->id);
});

test('recurring to payload array returns disabled when null', function (): void {
    expect(RecurringSchoolClass::toPayloadArray(null))->toBe([
        'enabled' => false,
        'type' => null,
        'interval' => 1,
        'daysOfWeek' => [],
    ]);
});

test('recurring weekday abbreviation list formats selected days', function (): void {
    $schoolClass = SchoolClass::factory()->for($this->owner)->create();
    $recurring = RecurringSchoolClass::query()->create([
        'school_class_id' => $schoolClass->id,
        'recurrence_type' => TaskRecurrenceType::Weekly,
        'interval' => 1,
        'start_datetime' => now(),
        'end_datetime' => now()->addMonth(),
        'days_of_week' => json_encode([1, 3]),
    ]);

    expect($recurring->fresh()->weekdayAbbreviationList())->toBe('Mon, Wed');
});

test('recurring to payload array maps stored recurring row', function (): void {
    $schoolClass = SchoolClass::factory()->for($this->owner)->create();
    $recurring = RecurringSchoolClass::query()->create([
        'school_class_id' => $schoolClass->id,
        'recurrence_type' => TaskRecurrenceType::Weekly,
        'interval' => 2,
        'start_datetime' => now(),
        'end_datetime' => null,
        'days_of_week' => json_encode([1, 3]),
    ]);

    $payload = RecurringSchoolClass::toPayloadArray($recurring->fresh());

    expect($payload['enabled'])->toBeTrue()
        ->and($payload['type'])->toBe('weekly')
        ->and($payload['interval'])->toBe(2)
        ->and($payload['daysOfWeek'])->toBe([1, 3]);
});

test('force deleting school class cascades recurring instances and exceptions', function (): void {
    $schoolClass = SchoolClass::factory()->for($this->owner)->create();
    $recurring = RecurringSchoolClass::factory()->create(['school_class_id' => $schoolClass->id]);
    $instance = SchoolClassInstance::factory()->create([
        'recurring_school_class_id' => $recurring->id,
        'school_class_id' => $schoolClass->id,
    ]);
    $exception = SchoolClassException::factory()->create([
        'recurring_school_class_id' => $recurring->id,
        'replacement_instance_id' => $instance->id,
    ]);

    $schoolClass->forceDelete();

    expect(SchoolClass::withTrashed()->find($schoolClass->id))->toBeNull()
        ->and(RecurringSchoolClass::query()->find($recurring->id))->toBeNull()
        ->and(SchoolClassInstance::query()->find($instance->id))->toBeNull()
        ->and(SchoolClassException::query()->find($exception->id))->toBeNull();
});

test('soft deleting school class does not remove recurring row', function (): void {
    $schoolClass = SchoolClass::factory()->for($this->owner)->create();
    $recurring = RecurringSchoolClass::factory()->create(['school_class_id' => $schoolClass->id]);

    $schoolClass->delete();

    expect(SchoolClass::withTrashed()->find($schoolClass->id))->not->toBeNull()
        ->and(RecurringSchoolClass::query()->find($recurring->id))->not->toBeNull();
});
