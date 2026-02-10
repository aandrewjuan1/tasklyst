<?php

use App\Enums\TaskStatus;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('task instance belongs to recurring task', function (): void {
    $recurring = RecurringTask::factory()->create();
    $instance = TaskInstance::factory()->create(['recurring_task_id' => $recurring->id]);

    expect($instance->recurringTask)->not->toBeNull()
        ->and($instance->recurringTask->id)->toBe($recurring->id);
});

test('task instance belongs to task', function (): void {
    $task = Task::factory()->for($this->user)->create();
    $recurring = RecurringTask::factory()->create(['task_id' => $task->id]);
    $instance = TaskInstance::factory()->create([
        'recurring_task_id' => $recurring->id,
        'task_id' => $task->id,
    ]);

    expect($instance->task)->not->toBeNull()
        ->and($instance->task->id)->toBe($task->id);
});

test('task instance casts instance date to date', function (): void {
    $instance = TaskInstance::factory()->create(['instance_date' => '2025-02-10']);

    expect($instance->instance_date)->toBeInstanceOf(\Carbon\CarbonInterface::class)
        ->and($instance->instance_date->format('Y-m-d'))->toBe('2025-02-10');
});

test('task instance casts status to enum', function (): void {
    $instance = TaskInstance::factory()->create(['status' => TaskStatus::Done]);

    expect($instance->status)->toBe(TaskStatus::Done);
});
