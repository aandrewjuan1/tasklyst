<?php

use App\Enums\TaskRecurrenceType;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\TaskException;
use App\Models\TaskInstance;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('recurring task belongs to task', function (): void {
    $task = Task::factory()->for($this->user)->create();
    $recurring = RecurringTask::factory()->create(['task_id' => $task->id]);

    expect($recurring->task)->not->toBeNull()
        ->and($recurring->task->id)->toBe($task->id);
});

test('recurring task has many task instances', function (): void {
    $recurring = RecurringTask::factory()->create();
    TaskInstance::factory()->create(['recurring_task_id' => $recurring->id]);
    TaskInstance::factory()->create(['recurring_task_id' => $recurring->id]);

    expect($recurring->taskInstances)->toHaveCount(2);
});

test('recurring task has many task exceptions', function (): void {
    $recurring = RecurringTask::factory()->create();
    TaskException::factory()->create(['recurring_task_id' => $recurring->id]);

    expect($recurring->taskExceptions)->toHaveCount(1);
});

test('to payload array returns disabled structure when null', function (): void {
    $payload = RecurringTask::toPayloadArray(null);

    expect($payload)->toEqual([
        'enabled' => false,
        'type' => null,
        'interval' => 1,
        'daysOfWeek' => [],
    ]);
});

test('to payload array returns enabled structure with type interval and days of week', function (): void {
    $recurring = RecurringTask::factory()->create([
        'recurrence_type' => TaskRecurrenceType::Weekly,
        'interval' => 2,
        'days_of_week' => json_encode([1, 3]),
    ]);

    $payload = RecurringTask::toPayloadArray($recurring);

    expect($payload['enabled'])->toBeTrue()
        ->and($payload['type'])->toBe('weekly')
        ->and($payload['interval'])->toBe(2)
        ->and($payload['daysOfWeek'])->toEqual([1, 3]);
});

test('recurring task casts recurrence type to enum', function (): void {
    $recurring = RecurringTask::factory()->create(['recurrence_type' => TaskRecurrenceType::Monthly]);

    expect($recurring->recurrence_type)->toBe(TaskRecurrenceType::Monthly);
});

test('recurring task casts start and end datetime', function (): void {
    $start = now()->startOfDay();
    $end = now()->addDays(7);
    $recurring = RecurringTask::factory()->create([
        'start_datetime' => $start,
        'end_datetime' => $end,
    ]);

    expect($recurring->start_datetime)->toBeInstanceOf(\Carbon\CarbonInterface::class)
        ->and($recurring->end_datetime)->toBeInstanceOf(\Carbon\CarbonInterface::class);
});
