<?php

use App\Models\RecurringTask;
use App\Models\TaskException;
use App\Models\TaskInstance;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('task exception belongs to recurring task', function (): void {
    $recurring = RecurringTask::factory()->create();
    $exception = TaskException::factory()->create(['recurring_task_id' => $recurring->id]);

    expect($exception->recurringTask)->not->toBeNull()
        ->and($exception->recurringTask->id)->toBe($recurring->id);
});

test('task exception belongs to replacement instance when set', function (): void {
    $recurring = RecurringTask::factory()->create();
    $instance = TaskInstance::factory()->create(['recurring_task_id' => $recurring->id]);
    $exception = TaskException::factory()->create([
        'recurring_task_id' => $recurring->id,
        'replacement_instance_id' => $instance->id,
    ]);

    expect($exception->replacementInstance)->not->toBeNull()
        ->and($exception->replacementInstance->id)->toBe($instance->id);
});

test('task exception casts exception date to date', function (): void {
    $exception = TaskException::factory()->create(['exception_date' => '2025-02-10']);

    expect($exception->exception_date)->toBeInstanceOf(\Carbon\CarbonInterface::class)
        ->and($exception->exception_date->format('Y-m-d'))->toBe('2025-02-10');
});

test('task exception casts is deleted to boolean', function (): void {
    $exception = TaskException::factory()->create(['is_deleted' => true]);

    expect($exception->is_deleted)->toBeTrue();
});

test('task exception belongs to created by user', function (): void {
    $exception = TaskException::factory()->create(['created_by' => $this->user->id]);

    expect($exception->createdBy)->not->toBeNull()
        ->and($exception->createdBy->id)->toBe($this->user->id);
});
