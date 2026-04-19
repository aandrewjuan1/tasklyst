<?php

use App\Enums\TaskRecurrenceType;
use App\Models\RecurringEvent;
use App\Models\RecurringSchoolClass;
use App\Models\RecurringTask;
use App\Models\SchoolClass;
use App\Models\SchoolClassException;
use App\Models\SchoolClassInstance;
use App\Models\Task;
use App\Models\TaskException;
use App\Models\TaskInstance;
use App\Services\RecurrenceExpander;
use Carbon\Carbon;
use Illuminate\Support\Collection;

beforeEach(function (): void {
    $this->expander = app(RecurrenceExpander::class);
});

test('expand daily returns one date per day in range with interval one', function (): void {
    $start = Carbon::parse('2025-02-01');
    $recurring = RecurringTask::factory()->create([
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => $start->copy()->subDay(),
        'end_datetime' => null,
    ]);

    $dates = $this->expander->expand($recurring, Carbon::parse('2025-02-01'), Carbon::parse('2025-02-05'));

    expect($dates)->toHaveCount(5)
        ->and($dates[0]->format('Y-m-d'))->toBe('2025-02-01')
        ->and($dates[4]->format('Y-m-d'))->toBe('2025-02-05');
});

test('expand daily with interval two returns every second day', function (): void {
    $start = Carbon::parse('2025-02-01');
    $recurring = RecurringTask::factory()->create([
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 2,
        'start_datetime' => $start,
        'end_datetime' => null,
    ]);

    $dates = $this->expander->expand($recurring, Carbon::parse('2025-02-01'), Carbon::parse('2025-02-07'));

    expect($dates)->toHaveCount(4) // 1st, 3rd, 5th, 7th
        ->and($dates[0]->format('Y-m-d'))->toBe('2025-02-01')
        ->and($dates[1]->format('Y-m-d'))->toBe('2025-02-03')
        ->and($dates[3]->format('Y-m-d'))->toBe('2025-02-07');
});

test('expand daily respects recurring start and end datetime', function (): void {
    $recurring = RecurringTask::factory()->create([
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2025-02-03'),
        'end_datetime' => Carbon::parse('2025-02-05'),
    ]);

    $dates = $this->expander->expand($recurring, Carbon::parse('2025-02-01'), Carbon::parse('2025-02-10'));

    expect($dates)->toHaveCount(3)
        ->and($dates[0]->format('Y-m-d'))->toBe('2025-02-03')
        ->and($dates[2]->format('Y-m-d'))->toBe('2025-02-05');
});

test('expand returns empty when request range is entirely before recurring start', function (): void {
    $recurring = RecurringTask::factory()->create([
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2025-02-10'),
        'end_datetime' => null,
    ]);

    $dates = $this->expander->expand($recurring, Carbon::parse('2025-02-01'), Carbon::parse('2025-02-05'));

    expect($dates)->toBeEmpty();
});

test('expand weekly returns dates for specified days of week', function (): void {
    $recurringStart = Carbon::parse('2025-02-03'); // Monday
    $recurring = RecurringTask::factory()->create([
        'recurrence_type' => TaskRecurrenceType::Weekly,
        'interval' => 1,
        'start_datetime' => $recurringStart,
        'end_datetime' => null,
        'days_of_week' => json_encode([1, 3]), // Monday, Wednesday
    ]);

    $dates = $this->expander->expand($recurring, Carbon::parse('2025-02-03'), Carbon::parse('2025-02-16'));

    expect($dates)->toHaveCount(4); // Mon 3, Wed 5, Mon 10, Wed 12
    $formatted = array_map(fn ($d) => $d->format('Y-m-d'), $dates);
    expect($formatted)->toContain('2025-02-03', '2025-02-05', '2025-02-10', '2025-02-12');
});

test('expand monthly returns same day of month in range', function (): void {
    $recurring = RecurringTask::factory()->create([
        'recurrence_type' => TaskRecurrenceType::Monthly,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2025-02-15'),
        'end_datetime' => null,
    ]);

    $dates = $this->expander->expand($recurring, Carbon::parse('2025-02-01'), Carbon::parse('2025-04-30'));

    expect($dates)->toHaveCount(3)
        ->and($dates[0]->format('Y-m-d'))->toBe('2025-02-15')
        ->and($dates[1]->format('Y-m-d'))->toBe('2025-03-15')
        ->and($dates[2]->format('Y-m-d'))->toBe('2025-04-15');
});

test('expand monthly with interval two returns every second month', function (): void {
    $recurring = RecurringTask::factory()->create([
        'recurrence_type' => TaskRecurrenceType::Monthly,
        'interval' => 2,
        'start_datetime' => Carbon::parse('2025-02-10'),
        'end_datetime' => null,
    ]);

    $dates = $this->expander->expand($recurring, Carbon::parse('2025-02-01'), Carbon::parse('2025-07-31'));

    expect($dates)->toHaveCount(3) // Feb, Apr, Jun
        ->and($dates[0]->format('Y-m-d'))->toBe('2025-02-10')
        ->and($dates[1]->format('Y-m-d'))->toBe('2025-04-10')
        ->and($dates[2]->format('Y-m-d'))->toBe('2025-06-10');
});

test('expand yearly returns same day and month in range', function (): void {
    $recurring = RecurringTask::factory()->create([
        'recurrence_type' => TaskRecurrenceType::Yearly,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2025-06-15'),
        'end_datetime' => null,
    ]);

    $dates = $this->expander->expand($recurring, Carbon::parse('2025-01-01'), Carbon::parse('2027-12-31'));

    expect($dates)->toHaveCount(3)
        ->and($dates[0]->format('Y-m-d'))->toBe('2025-06-15')
        ->and($dates[1]->format('Y-m-d'))->toBe('2026-06-15')
        ->and($dates[2]->format('Y-m-d'))->toBe('2027-06-15');
});

test('expand excludes dates that have deleted exception', function (): void {
    $recurring = RecurringTask::factory()->create([
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2025-02-01'),
        'end_datetime' => Carbon::parse('2025-02-05'),
    ]);
    TaskException::factory()->create([
        'recurring_task_id' => $recurring->id,
        'exception_date' => Carbon::parse('2025-02-03'),
        'is_deleted' => true,
    ]);

    $dates = $this->expander->expand($recurring, Carbon::parse('2025-02-01'), Carbon::parse('2025-02-05'));

    $formatted = array_map(fn ($d) => $d->format('Y-m-d'), $dates);
    expect($formatted)->not->toContain('2025-02-03')
        ->and($dates)->toHaveCount(4);
});

test('expand includes replacement instance date when exception has replacement', function (): void {
    $recurring = RecurringTask::factory()->create([
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2025-02-01'),
        'end_datetime' => Carbon::parse('2025-02-05'),
    ]);
    $replacementInstance = TaskInstance::factory()->create([
        'recurring_task_id' => $recurring->id,
        'task_id' => $recurring->task_id,
        'instance_date' => Carbon::parse('2025-02-04'),
    ]);
    TaskException::factory()->create([
        'recurring_task_id' => $recurring->id,
        'exception_date' => Carbon::parse('2025-02-03'),
        'is_deleted' => false,
        'replacement_instance_id' => $replacementInstance->id,
    ]);

    $dates = $this->expander->expand($recurring, Carbon::parse('2025-02-01'), Carbon::parse('2025-02-05'));

    $formatted = array_map(fn ($d) => $d->format('Y-m-d'), $dates);
    expect($formatted)->not->toContain('2025-02-03')
        ->and($formatted)->toContain('2025-02-04');
});

test('expand works with RecurringEvent', function (): void {
    $start = Carbon::parse('2025-02-01');
    $recurring = RecurringEvent::factory()->create([
        'recurrence_type' => \App\Enums\EventRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => $start,
        'end_datetime' => null,
    ]);

    $dates = $this->expander->expand($recurring, Carbon::parse('2025-02-01'), Carbon::parse('2025-02-03'));

    expect($dates)->toHaveCount(3);
});

test('getRelevantRecurringIdsForDate returns task and event ids that have occurrence on date', function (): void {
    $date = Carbon::parse('2025-02-10');
    $taskRecurring = RecurringTask::factory()->create([
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2025-02-01'),
        'end_datetime' => Carbon::parse('2025-02-28'),
    ]);
    $eventRecurring = RecurringEvent::factory()->create([
        'recurrence_type' => \App\Enums\EventRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2025-02-01'),
        'end_datetime' => Carbon::parse('2025-02-28'),
    ]);

    $result = $this->expander->getRelevantRecurringIdsForDate(
        collect([$taskRecurring]),
        collect([$eventRecurring]),
        $date
    );

    expect($result['task_ids'])->toContain($taskRecurring->id)
        ->and($result['event_ids'])->toContain($eventRecurring->id)
        ->and($result['recurring_school_class_ids'])->toBe([]);
});

test('getRelevantRecurringIdsForDate excludes recurring when date outside range', function (): void {
    $date = Carbon::parse('2025-03-15');
    $taskRecurring = RecurringTask::factory()->create([
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2025-02-01'),
        'end_datetime' => Carbon::parse('2025-02-28'),
    ]);

    $result = $this->expander->getRelevantRecurringIdsForDate(collect([$taskRecurring]), collect(), $date);

    expect($result['task_ids'])->toBeEmpty();
});

test('getRelevantRecurringIdsForDate includes recurring with null start and end as relevant for any date', function (): void {
    $taskRecurring = RecurringTask::factory()->create([
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => null,
        'end_datetime' => null,
    ]);

    $result = $this->expander->getRelevantRecurringIdsForDate(collect([$taskRecurring]), collect(), Carbon::parse('2025-02-10'));

    expect($result['task_ids'])->toContain($taskRecurring->id);
});

test('getRelevantRecurringIdsForDate anchors weekly recurrence with null start to task created date', function (): void {
    $task = Task::factory()->create([
        'created_at' => Carbon::parse('2025-02-03 09:00:00'), // Monday
    ]);
    $taskRecurring = RecurringTask::factory()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Weekly,
        'interval' => 1,
        'start_datetime' => null,
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    $monday = Carbon::parse('2025-02-10');
    $tuesday = Carbon::parse('2025-02-11');

    $mondayResult = $this->expander->getRelevantRecurringIdsForDate(collect([$taskRecurring]), collect(), $monday);
    $tuesdayResult = $this->expander->getRelevantRecurringIdsForDate(collect([$taskRecurring]), collect(), $tuesday);

    expect($mondayResult['task_ids'])->toContain($taskRecurring->id)
        ->and($tuesdayResult['task_ids'])->not->toContain($taskRecurring->id);
});

test('expand monthly anchors recurrence with null start to task creation day', function (): void {
    $task = Task::factory()->create([
        'created_at' => Carbon::parse('2025-01-15 08:30:00'),
    ]);
    $taskRecurring = RecurringTask::factory()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Monthly,
        'interval' => 1,
        'start_datetime' => null,
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    $dates = $this->expander->expand($taskRecurring, Carbon::parse('2025-02-01'), Carbon::parse('2025-04-30'));
    $formatted = array_map(fn ($d) => $d->format('Y-m-d'), $dates);

    expect($formatted)->toEqual([
        '2025-02-15',
        '2025-03-15',
        '2025-04-15',
    ]);
});

test('expand yearly anchors recurrence with null start to task creation month and day', function (): void {
    $task = Task::factory()->create([
        'created_at' => Carbon::parse('2023-06-20 10:00:00'),
    ]);
    $taskRecurring = RecurringTask::factory()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Yearly,
        'interval' => 1,
        'start_datetime' => null,
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    $dates = $this->expander->expand($taskRecurring, Carbon::parse('2025-01-01'), Carbon::parse('2027-12-31'));
    $formatted = array_map(fn ($d) => $d->format('Y-m-d'), $dates);

    expect($formatted)->toEqual([
        '2025-06-20',
        '2026-06-20',
        '2027-06-20',
    ]);
});

test('expand monthly includes first occurrence when explicit start has time component', function (): void {
    $recurring = RecurringTask::factory()->create([
        'recurrence_type' => TaskRecurrenceType::Monthly,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-04-14 14:45:00'),
        'end_datetime' => null,
    ]);

    $dates = $this->expander->expand($recurring, Carbon::parse('2026-04-14 00:00:00'), Carbon::parse('2026-06-30 23:59:59'));
    $formatted = array_map(fn ($d) => $d->format('Y-m-d'), $dates);

    expect($formatted)->toEqual([
        '2026-04-14',
        '2026-05-14',
        '2026-06-14',
    ]);
});

test('expand yearly includes first occurrence when explicit start has time component', function (): void {
    $recurring = RecurringTask::factory()->create([
        'recurrence_type' => TaskRecurrenceType::Yearly,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-04-14 14:45:00'),
        'end_datetime' => null,
    ]);

    $dates = $this->expander->expand($recurring, Carbon::parse('2026-01-01 00:00:00'), Carbon::parse('2028-12-31 23:59:59'));
    $formatted = array_map(fn ($d) => $d->format('Y-m-d'), $dates);

    expect($formatted)->toEqual([
        '2026-04-14',
        '2027-04-14',
        '2028-04-14',
    ]);
});

test('getRelevantRecurringIdsForDate excludes recurring with null start and end when exception skips that date', function (): void {
    $taskRecurring = RecurringTask::factory()->create([
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => null,
        'end_datetime' => null,
    ]);
    TaskException::factory()->create([
        'recurring_task_id' => $taskRecurring->id,
        'exception_date' => Carbon::parse('2025-02-10'),
        'is_deleted' => true,
    ]);

    $result = $this->expander->getRelevantRecurringIdsForDate(collect([$taskRecurring]), collect(), Carbon::parse('2025-02-10'));

    expect($result['task_ids'])->not->toContain($taskRecurring->id);
});

test('expand uses preloaded exceptions when provided to avoid N+1', function (): void {
    $recurring = RecurringTask::factory()->create([
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2025-02-01'),
        'end_datetime' => Carbon::parse('2025-02-05'),
    ]);
    TaskException::factory()->create([
        'recurring_task_id' => $recurring->id,
        'exception_date' => Carbon::parse('2025-02-03'),
        'is_deleted' => true,
    ]);
    $preloaded = new Collection($recurring->taskExceptions()->get());

    $dates = $this->expander->expand($recurring, Carbon::parse('2025-02-01'), Carbon::parse('2025-02-05'), $preloaded);

    $formatted = array_map(fn ($d) => $d->format('Y-m-d'), $dates);
    expect($formatted)->not->toContain('2025-02-03');
});

test('expand works with RecurringSchoolClass and excludes deleted exception date', function (): void {
    $schoolClass = SchoolClass::factory()->create([
        'start_datetime' => Carbon::parse('2025-02-01'),
        'end_datetime' => Carbon::parse('2025-02-05'),
    ]);
    $recurring = RecurringSchoolClass::factory()->create([
        'school_class_id' => $schoolClass->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2025-02-01'),
        'end_datetime' => Carbon::parse('2025-02-05'),
    ]);
    SchoolClassException::factory()->create([
        'recurring_school_class_id' => $recurring->id,
        'exception_date' => Carbon::parse('2025-02-03'),
        'is_deleted' => true,
    ]);

    $dates = $this->expander->expand($recurring, Carbon::parse('2025-02-01'), Carbon::parse('2025-02-05'));
    $formatted = array_map(fn ($d) => $d->format('Y-m-d'), $dates);

    expect($formatted)->not->toContain('2025-02-03')
        ->and($dates)->toHaveCount(4);
});

test('expand includes replacement instance date for school class exception', function (): void {
    $schoolClass = SchoolClass::factory()->create([
        'start_datetime' => Carbon::parse('2025-02-01'),
        'end_datetime' => Carbon::parse('2025-02-05'),
    ]);
    $recurring = RecurringSchoolClass::factory()->create([
        'school_class_id' => $schoolClass->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2025-02-01'),
        'end_datetime' => Carbon::parse('2025-02-05'),
    ]);
    $replacementInstance = SchoolClassInstance::factory()->create([
        'recurring_school_class_id' => $recurring->id,
        'school_class_id' => $schoolClass->id,
        'instance_date' => Carbon::parse('2025-02-04'),
    ]);
    SchoolClassException::factory()->create([
        'recurring_school_class_id' => $recurring->id,
        'exception_date' => Carbon::parse('2025-02-03'),
        'is_deleted' => false,
        'replacement_instance_id' => $replacementInstance->id,
    ]);

    $dates = $this->expander->expand($recurring, Carbon::parse('2025-02-01'), Carbon::parse('2025-02-05'));
    $formatted = array_map(fn ($d) => $d->format('Y-m-d'), $dates);

    expect($formatted)->not->toContain('2025-02-03')
        ->and($formatted)->toContain('2025-02-04');
});

test('getRelevantRecurringIdsForDate returns recurring school class ids that have occurrence on date', function (): void {
    $date = Carbon::parse('2025-02-10');
    $schoolClass = SchoolClass::factory()->create();
    $schoolRecurring = RecurringSchoolClass::factory()->create([
        'school_class_id' => $schoolClass->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2025-02-01'),
        'end_datetime' => Carbon::parse('2025-02-28'),
    ]);

    $result = $this->expander->getRelevantRecurringIdsForDate(
        collect(),
        collect(),
        $date,
        collect([$schoolRecurring])
    );

    expect($result['recurring_school_class_ids'])->toContain($schoolRecurring->id)
        ->and($result['task_ids'])->toBe([])
        ->and($result['event_ids'])->toBe([]);
});
