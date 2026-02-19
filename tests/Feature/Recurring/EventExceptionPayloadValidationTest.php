<?php

use App\DataTransferObjects\Event\CreateEventExceptionDto;
use App\DataTransferObjects\Event\UpdateEventExceptionDto;
use App\Models\RecurringEvent;
use App\Support\Validation\EventExceptionPayloadValidation;
use Illuminate\Support\Facades\Validator;

beforeEach(function (): void {
    $this->recurring = RecurringEvent::factory()->create();
});

test('valid create event exception payload passes validation', function (): void {
    $payload = array_replace_recursive(EventExceptionPayloadValidation::createDefaults(), [
        'recurringEventId' => $this->recurring->id,
        'exceptionDate' => '2025-02-10',
        'isDeleted' => true,
    ]);

    $validator = Validator::make(
        ['eventExceptionPayload' => $payload],
        EventExceptionPayloadValidation::createRules()
    );

    expect($validator->passes())->toBeTrue();
});

test('create event exception payload fails when recurring event id is missing', function (): void {
    $payload = array_replace_recursive(EventExceptionPayloadValidation::createDefaults(), [
        'exceptionDate' => '2025-02-10',
    ]);
    unset($payload['recurringEventId']);

    $validator = Validator::make(
        ['eventExceptionPayload' => $payload],
        EventExceptionPayloadValidation::createRules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('eventExceptionPayload.recurringEventId'))->toBeTrue();
});

test('create event exception payload fails when recurring event id does not exist', function (): void {
    $payload = array_replace_recursive(EventExceptionPayloadValidation::createDefaults(), [
        'recurringEventId' => 99999,
        'exceptionDate' => '2025-02-10',
    ]);

    $validator = Validator::make(
        ['eventExceptionPayload' => $payload],
        EventExceptionPayloadValidation::createRules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('eventExceptionPayload.recurringEventId'))->toBeTrue();
});

test('create event exception dto from validated maps all fields', function (): void {
    $validated = [
        'recurringEventId' => $this->recurring->id,
        'exceptionDate' => '2025-02-10',
        'isDeleted' => false,
        'reason' => '  Cancelled  ',
    ];

    $dto = CreateEventExceptionDto::fromValidated($validated);

    expect($dto->recurringEventId)->toBe($this->recurring->id)
        ->and($dto->exceptionDate)->toBe('2025-02-10')
        ->and($dto->isDeleted)->toBeFalse()
        ->and($dto->reason)->toBe('Cancelled');
});

test('valid update event exception payload passes validation', function (): void {
    $payload = array_replace_recursive(EventExceptionPayloadValidation::updateDefaults(), [
        'reason' => 'Updated reason',
    ]);

    $validator = Validator::make(
        ['eventExceptionPayload' => $payload],
        EventExceptionPayloadValidation::updateRules()
    );

    expect($validator->passes())->toBeTrue();
});

test('update event exception dto toServiceAttributes returns only set attributes', function (): void {
    $dto = new UpdateEventExceptionDto(isDeleted: false, reason: 'Rescheduled', replacementInstanceId: null);

    $attrs = $dto->toServiceAttributes();

    expect($attrs)->toBe([
        'is_deleted' => false,
        'reason' => 'Rescheduled',
    ]);
});
