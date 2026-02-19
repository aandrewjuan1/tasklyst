<?php

use App\Enums\TaskRecurrenceType;
use App\Models\RecurringEvent;
use App\Models\RecurringTask;
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
        ->and($result['event_ids'])->toContain($eventRecurring->id);
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
