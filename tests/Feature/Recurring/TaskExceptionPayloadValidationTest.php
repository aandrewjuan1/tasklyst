<?php

use App\DataTransferObjects\Task\CreateTaskExceptionDto;
use App\DataTransferObjects\Task\UpdateTaskExceptionDto;
use App\Models\RecurringTask;
use App\Support\Validation\TaskExceptionPayloadValidation;
use Illuminate\Support\Facades\Validator;

beforeEach(function (): void {
    $this->recurring = RecurringTask::factory()->create();
});

test('valid create task exception payload passes validation', function (): void {
    $payload = array_replace_recursive(TaskExceptionPayloadValidation::createDefaults(), [
        'recurringTaskId' => $this->recurring->id,
        'exceptionDate' => '2025-02-10',
        'isDeleted' => true,
    ]);

    $validator = Validator::make(
        ['taskExceptionPayload' => $payload],
        TaskExceptionPayloadValidation::createRules()
    );

    expect($validator->passes())->toBeTrue();
});

test('create task exception payload fails when recurring task id is missing', function (): void {
    $payload = array_replace_recursive(TaskExceptionPayloadValidation::createDefaults(), [
        'exceptionDate' => '2025-02-10',
    ]);
    unset($payload['recurringTaskId']);

    $validator = Validator::make(
        ['taskExceptionPayload' => $payload],
        TaskExceptionPayloadValidation::createRules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('taskExceptionPayload.recurringTaskId'))->toBeTrue();
});

test('create task exception payload fails when recurring task id does not exist', function (): void {
    $payload = array_replace_recursive(TaskExceptionPayloadValidation::createDefaults(), [
        'recurringTaskId' => 99999,
        'exceptionDate' => '2025-02-10',
    ]);

    $validator = Validator::make(
        ['taskExceptionPayload' => $payload],
        TaskExceptionPayloadValidation::createRules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('taskExceptionPayload.recurringTaskId'))->toBeTrue();
});

test('create task exception payload fails when exception date is invalid', function (): void {
    $payload = array_replace_recursive(TaskExceptionPayloadValidation::createDefaults(), [
        'recurringTaskId' => $this->recurring->id,
        'exceptionDate' => 'not-a-date',
    ]);

    $validator = Validator::make(
        ['taskExceptionPayload' => $payload],
        TaskExceptionPayloadValidation::createRules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('taskExceptionPayload.exceptionDate'))->toBeTrue();
});

test('create task exception dto from validated maps all fields', function (): void {
    $validated = [
        'recurringTaskId' => $this->recurring->id,
        'exceptionDate' => '2025-02-10',
        'isDeleted' => false,
        'reason' => '  Rescheduled  ',
    ];

    $dto = CreateTaskExceptionDto::fromValidated($validated);

    expect($dto->recurringTaskId)->toBe($this->recurring->id)
        ->and($dto->exceptionDate)->toBe('2025-02-10')
        ->and($dto->isDeleted)->toBeFalse()
        ->and($dto->reason)->toBe('Rescheduled');
});

test('create task exception dto from validated defaults isDeleted to true when missing', function (): void {
    $validated = [
        'recurringTaskId' => $this->recurring->id,
        'exceptionDate' => '2025-02-10',
    ];

    $dto = CreateTaskExceptionDto::fromValidated($validated);

    expect($dto->isDeleted)->toBeTrue()
        ->and($dto->reason)->toBeNull();
});

test('valid update task exception payload passes validation', function (): void {
    $payload = array_replace_recursive(TaskExceptionPayloadValidation::updateDefaults(), [
        'isDeleted' => false,
        'reason' => 'Updated reason',
    ]);

    $validator = Validator::make(
        ['taskExceptionPayload' => $payload],
        TaskExceptionPayloadValidation::updateRules()
    );

    expect($validator->passes())->toBeTrue();
});

test('update task exception dto from validated maps fields', function (): void {
    $validated = [
        'isDeleted' => false,
        'reason' => '  New reason  ',
    ];

    $dto = UpdateTaskExceptionDto::fromValidated($validated);

    expect($dto->isDeleted)->toBeFalse()
        ->and($dto->reason)->toBe('New reason');
});

test('update task exception dto toServiceAttributes returns only set attributes', function (): void {
    $dto = new UpdateTaskExceptionDto(isDeleted: false, reason: 'Rescheduled', replacementInstanceId: null);

    $attrs = $dto->toServiceAttributes();

    expect($attrs)->toBe([
        'is_deleted' => false,
        'reason' => 'Rescheduled',
    ]);
});
