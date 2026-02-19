<?php

use App\Actions\Task\CreateTaskExceptionAction;
use App\Actions\Task\DeleteTaskExceptionAction;
use App\Actions\Task\UpdateTaskExceptionAction;
use App\DataTransferObjects\Task\CreateTaskExceptionDto;
use App\DataTransferObjects\Task\UpdateTaskExceptionDto;
use App\Models\RecurringTask;
use App\Models\TaskException;
use App\Models\TaskInstance;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->recurring = RecurringTask::factory()->create();
    $this->createAction = app(CreateTaskExceptionAction::class);
    $this->deleteAction = app(DeleteTaskExceptionAction::class);
    $this->updateAction = app(UpdateTaskExceptionAction::class);
});

test('create task exception action creates exception via service', function (): void {
    $dto = new CreateTaskExceptionDto(
        recurringTaskId: $this->recurring->id,
        exceptionDate: '2025-02-10',
        isDeleted: true,
        replacementInstanceId: null,
        reason: 'Skipped'
    );

    $exception = $this->createAction->execute($this->user, $dto);

    expect($exception)->toBeInstanceOf(TaskException::class)
        ->and($exception->recurring_task_id)->toBe($this->recurring->id)
        ->and($exception->exception_date->format('Y-m-d'))->toBe('2025-02-10')
        ->and($exception->is_deleted)->toBeTrue()
        ->and($exception->reason)->toBe('Skipped')
        ->and($exception->created_by)->toBe($this->user->id);
});

test('create task exception action from validated payload', function (): void {
    $validated = [
        'recurringTaskId' => $this->recurring->id,
        'exceptionDate' => '2025-02-15',
        'isDeleted' => true,
        'reason' => null,
    ];
    $dto = CreateTaskExceptionDto::fromValidated($validated);

    $exception = $this->createAction->execute($this->user, $dto);

    expect($exception->exception_date->format('Y-m-d'))->toBe('2025-02-15');
});

test('create task exception action with replacement instance', function (): void {
    $instance = TaskInstance::factory()->create([
        'recurring_task_id' => $this->recurring->id,
        'task_id' => $this->recurring->task_id,
        'instance_date' => Carbon::parse('2025-02-12'),
    ]);
    $dto = new CreateTaskExceptionDto(
        recurringTaskId: $this->recurring->id,
        exceptionDate: '2025-02-10',
        isDeleted: false,
        replacementInstanceId: $instance->id,
        reason: 'Moved'
    );

    $exception = $this->createAction->execute($this->user, $dto);

    expect($exception->replacement_instance_id)->toBe($instance->id)
        ->and($exception->is_deleted)->toBeFalse();
});

test('create task exception action throws when recurring task not found', function (): void {
    $dto = new CreateTaskExceptionDto(
        recurringTaskId: 99999,
        exceptionDate: '2025-02-10',
        isDeleted: true,
        replacementInstanceId: null,
        reason: null
    );

    $this->createAction->execute($this->user, $dto);
})->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

test('delete task exception action removes exception', function (): void {
    $exception = TaskException::factory()->create([
        'recurring_task_id' => $this->recurring->id,
        'exception_date' => Carbon::parse('2025-02-10'),
    ]);

    $result = $this->deleteAction->execute($exception);

    expect($result)->toBeTrue()
        ->and(TaskException::find($exception->id))->toBeNull();
});

test('update task exception action updates allowed attributes', function (): void {
    $exception = TaskException::factory()->create([
        'recurring_task_id' => $this->recurring->id,
        'exception_date' => Carbon::parse('2025-02-10'),
        'is_deleted' => true,
        'reason' => null,
    ]);
    $dto = new UpdateTaskExceptionDto(isDeleted: false, reason: 'Restored', replacementInstanceId: null);

    $updated = $this->updateAction->execute($exception, $dto);

    expect($updated->is_deleted)->toBeFalse()
        ->and($updated->reason)->toBe('Restored');
});

test('update task exception action returns fresh when no attributes', function (): void {
    $exception = TaskException::factory()->create([
        'recurring_task_id' => $this->recurring->id,
        'exception_date' => Carbon::parse('2025-02-10'),
        'reason' => 'Original',
    ]);
    $dto = new UpdateTaskExceptionDto(isDeleted: null, reason: null, replacementInstanceId: null);

    $updated = $this->updateAction->execute($exception, $dto);

    expect($updated->id)->toBe($exception->id)
        ->and($updated->reason)->toBe('Original');
});
