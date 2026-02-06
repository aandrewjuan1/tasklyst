<?php

use App\Enums\TaskRecurrenceType;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\TaskException;
use App\Models\TaskInstance;
use App\Services\RecurrenceExpander;
use Carbon\Carbon;

beforeEach(function (): void {
    $this->expander = new RecurrenceExpander;
});

it('expands daily recurrence with interval 1', function (): void {
    $task = Task::factory()->create([
        'start_datetime' => Carbon::parse('2026-02-01 09:00:00'),
    ]);
    $recurring = RecurringTask::factory()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-02-01 00:00:00'),
        'end_datetime' => Carbon::parse('2026-02-05 23:59:59'),
        'days_of_week' => null,
    ]);

    $dates = $this->expander->expand(
        $recurring,
        Carbon::parse('2026-02-01'),
        Carbon::parse('2026-02-05')
    );

    expect($dates)->toHaveCount(5);
    expect($dates[0]->format('Y-m-d'))->toBe('2026-02-01');
    expect($dates[4]->format('Y-m-d'))->toBe('2026-02-05');
});

it('expands daily recurrence with interval 2', function (): void {
    $task = Task::factory()->create([
        'start_datetime' => Carbon::parse('2026-02-01 09:00:00'),
    ]);
    $recurring = RecurringTask::factory()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 2,
        'start_datetime' => Carbon::parse('2026-02-01 00:00:00'),
        'end_datetime' => Carbon::parse('2026-02-10 23:59:59'),
        'days_of_week' => null,
    ]);

    $dates = $this->expander->expand(
        $recurring,
        Carbon::parse('2026-02-01'),
        Carbon::parse('2026-02-10')
    );

    expect($dates)->toHaveCount(5); // Feb 1, 3, 5, 7, 9
    expect($dates[0]->format('Y-m-d'))->toBe('2026-02-01');
    expect($dates[1]->format('Y-m-d'))->toBe('2026-02-03');
});

it('expands weekly recurrence on specified days', function (): void {
    $task = Task::factory()->create([
        'start_datetime' => Carbon::parse('2026-02-02 09:00:00'), // Monday
    ]);
    $recurring = RecurringTask::factory()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Weekly,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-02-02 00:00:00'),
        'end_datetime' => Carbon::parse('2026-02-28 23:59:59'),
        'days_of_week' => '[1,3,5]', // Mon, Wed, Fri
    ]);

    $dates = $this->expander->expand(
        $recurring,
        Carbon::parse('2026-02-01'),
        Carbon::parse('2026-02-14')
    );

    $dateStrings = collect($dates)->map(fn ($d) => $d->format('Y-m-d'))->toArray();
    expect($dateStrings)->toContain('2026-02-02'); // Mon
    expect($dateStrings)->toContain('2026-02-04'); // Wed
    expect($dateStrings)->toContain('2026-02-06'); // Fri
    expect($dateStrings)->toContain('2026-02-09'); // Mon
});

it('expands weekly recurrence including Sunday (day 0)', function (): void {
    $task = Task::factory()->create([
        'start_datetime' => Carbon::parse('2026-02-02 09:00:00'), // Monday
    ]);
    $recurring = RecurringTask::factory()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Weekly,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-02-02 00:00:00'),
        'end_datetime' => Carbon::parse('2026-02-28 23:59:59'),
        'days_of_week' => '[0,1]', // Sun, Mon (0=Sunday, 1=Monday)
    ]);

    $dates = $this->expander->expand(
        $recurring,
        Carbon::parse('2026-02-01'),
        Carbon::parse('2026-02-16')
    );

    $dateStrings = collect($dates)->map(fn ($d) => $d->format('Y-m-d'))->toArray();
    expect($dateStrings)->toContain('2026-02-02'); // Mon
    expect($dateStrings)->toContain('2026-02-08'); // Sun (week of Feb 2)
    expect($dateStrings)->toContain('2026-02-09'); // Mon
    expect($dateStrings)->toContain('2026-02-15'); // Sun (week of Feb 9)
});

it('expands monthly recurrence on same day of month', function (): void {
    $task = Task::factory()->create([
        'start_datetime' => Carbon::parse('2026-01-15 09:00:00'),
    ]);
    $recurring = RecurringTask::factory()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Monthly,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-01-15 00:00:00'),
        'end_datetime' => Carbon::parse('2026-04-15 23:59:59'),
        'days_of_week' => null,
    ]);

    $dates = $this->expander->expand(
        $recurring,
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-04-30')
    );

    expect($dates)->toHaveCount(4); // Jan 15, Feb 15, Mar 15, Apr 15
    expect($dates[0]->format('Y-m-d'))->toBe('2026-01-15');
    expect($dates[1]->format('Y-m-d'))->toBe('2026-02-15');
});

it('handles monthly recurrence when day exceeds month length', function (): void {
    $task = Task::factory()->create([
        'start_datetime' => Carbon::parse('2026-01-31 09:00:00'),
    ]);
    $recurring = RecurringTask::factory()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Monthly,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-01-31 00:00:00'),
        'end_datetime' => Carbon::parse('2026-04-30 23:59:59'),
        'days_of_week' => null,
    ]);

    $dates = $this->expander->expand(
        $recurring,
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-04-30')
    );

    expect($dates[0]->format('Y-m-d'))->toBe('2026-01-31');
    expect($dates[1]->format('Y-m-d'))->toBe('2026-02-28'); // Feb has 28 days in 2026
    expect($dates[2]->format('Y-m-d'))->toBe('2026-03-31');
});

it('expands yearly recurrence', function (): void {
    $task = Task::factory()->create([
        'start_datetime' => Carbon::parse('2026-06-15 09:00:00'),
    ]);
    $recurring = RecurringTask::factory()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Yearly,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-06-15 00:00:00'),
        'end_datetime' => Carbon::parse('2028-06-15 23:59:59'),
        'days_of_week' => null,
    ]);

    $dates = $this->expander->expand(
        $recurring,
        Carbon::parse('2026-01-01'),
        Carbon::parse('2028-12-31')
    );

    expect($dates)->toHaveCount(3); // 2026, 2027, 2028
    expect($dates[0]->format('Y-m-d'))->toBe('2026-06-15');
    expect($dates[1]->format('Y-m-d'))->toBe('2027-06-15');
    expect($dates[2]->format('Y-m-d'))->toBe('2028-06-15');
});

it('respects recurring start_datetime and end_datetime bounds', function (): void {
    $task = Task::factory()->create([
        'start_datetime' => Carbon::parse('2026-02-01 09:00:00'),
    ]);
    $recurring = RecurringTask::factory()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-02-05 00:00:00'),
        'end_datetime' => Carbon::parse('2026-02-08 23:59:59'),
        'days_of_week' => null,
    ]);

    $dates = $this->expander->expand(
        $recurring,
        Carbon::parse('2026-02-01'),
        Carbon::parse('2026-02-15')
    );

    expect($dates)->toHaveCount(4); // Feb 5, 6, 7, 8
    expect($dates[0]->format('Y-m-d'))->toBe('2026-02-05');
    expect($dates[3]->format('Y-m-d'))->toBe('2026-02-08');
});

it('excludes dates with is_deleted exception', function (): void {
    $task = Task::factory()->create([
        'start_datetime' => Carbon::parse('2026-02-01 09:00:00'),
    ]);
    $recurring = RecurringTask::factory()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-02-01 00:00:00'),
        'end_datetime' => Carbon::parse('2026-02-05 23:59:59'),
        'days_of_week' => null,
    ]);

    TaskException::factory()->create([
        'recurring_task_id' => $recurring->id,
        'exception_date' => Carbon::parse('2026-02-03'),
        'is_deleted' => true,
        'replacement_instance_id' => null,
    ]);

    $dates = $this->expander->expand(
        $recurring,
        Carbon::parse('2026-02-01'),
        Carbon::parse('2026-02-05')
    );

    $dateStrings = collect($dates)->map(fn ($d) => $d->format('Y-m-d'))->toArray();
    expect($dateStrings)->not->toContain('2026-02-03');
    expect($dateStrings)->toContain('2026-02-01');
    expect($dateStrings)->toContain('2026-02-02');
    expect($dateStrings)->toContain('2026-02-04');
    expect($dateStrings)->toContain('2026-02-05');
    expect($dates)->toHaveCount(4);
});

it('excludes original date and includes replacement date when replacement_instance_id is set', function (): void {
    $task = Task::factory()->create([
        'start_datetime' => Carbon::parse('2026-02-01 09:00:00'),
    ]);
    $recurring = RecurringTask::factory()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-02-01 00:00:00'),
        'end_datetime' => Carbon::parse('2026-02-07 23:59:59'),
        'days_of_week' => null,
    ]);

    $replacementInstance = TaskInstance::factory()->create([
        'recurring_task_id' => $recurring->id,
        'task_id' => $task->id,
        'instance_date' => Carbon::parse('2026-02-06'),
    ]);

    TaskException::factory()->create([
        'recurring_task_id' => $recurring->id,
        'exception_date' => Carbon::parse('2026-02-03'),
        'is_deleted' => false,
        'replacement_instance_id' => $replacementInstance->id,
    ]);

    $dates = $this->expander->expand(
        $recurring,
        Carbon::parse('2026-02-01'),
        Carbon::parse('2026-02-07')
    );

    $dateStrings = collect($dates)->map(fn ($d) => $d->format('Y-m-d'))->toArray();
    expect($dateStrings)->not->toContain('2026-02-03');
    expect($dateStrings)->toContain('2026-02-06');
    expect($dateStrings)->toContain('2026-02-01');
    expect($dateStrings)->toContain('2026-02-02');
    expect($dateStrings)->toContain('2026-02-04');
    expect($dateStrings)->toContain('2026-02-05');
    expect($dateStrings)->toContain('2026-02-07');
});
