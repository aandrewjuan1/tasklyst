<?php

use App\Actions\Event\CreateEventExceptionAction;
use App\Actions\Event\DeleteEventExceptionAction;
use App\Actions\Event\UpdateEventExceptionAction;
use App\DataTransferObjects\Event\CreateEventExceptionDto;
use App\DataTransferObjects\Event\UpdateEventExceptionDto;
use App\Models\EventException;
use App\Models\EventInstance;
use App\Models\RecurringEvent;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->recurring = RecurringEvent::factory()->create();
    $this->createAction = app(CreateEventExceptionAction::class);
    $this->deleteAction = app(DeleteEventExceptionAction::class);
    $this->updateAction = app(UpdateEventExceptionAction::class);
});

test('create event exception action creates exception via service', function (): void {
    $dto = new CreateEventExceptionDto(
        recurringEventId: $this->recurring->id,
        exceptionDate: '2025-02-10',
        isDeleted: true,
        replacementInstanceId: null,
        reason: 'Skipped'
    );

    $exception = $this->createAction->execute($this->user, $dto);

    expect($exception)->toBeInstanceOf(EventException::class)
        ->and($exception->recurring_event_id)->toBe($this->recurring->id)
        ->and($exception->exception_date->format('Y-m-d'))->toBe('2025-02-10')
        ->and($exception->is_deleted)->toBeTrue()
        ->and($exception->reason)->toBe('Skipped')
        ->and($exception->created_by)->toBe($this->user->id);
});

test('create event exception action from validated payload', function (): void {
    $validated = [
        'recurringEventId' => $this->recurring->id,
        'exceptionDate' => '2025-02-15',
        'isDeleted' => true,
        'reason' => null,
    ];
    $dto = CreateEventExceptionDto::fromValidated($validated);

    $exception = $this->createAction->execute($this->user, $dto);

    expect($exception->exception_date->format('Y-m-d'))->toBe('2025-02-15');
});

test('create event exception action with replacement instance', function (): void {
    $instance = EventInstance::factory()->create([
        'recurring_event_id' => $this->recurring->id,
        'event_id' => $this->recurring->event_id,
        'instance_date' => Carbon::parse('2025-02-12'),
    ]);
    $dto = new CreateEventExceptionDto(
        recurringEventId: $this->recurring->id,
        exceptionDate: '2025-02-10',
        isDeleted: false,
        replacementInstanceId: $instance->id,
        reason: 'Moved'
    );

    $exception = $this->createAction->execute($this->user, $dto);

    expect($exception->replacement_instance_id)->toBe($instance->id)
        ->and($exception->is_deleted)->toBeFalse();
});

test('create event exception action throws when recurring event not found', function (): void {
    $dto = new CreateEventExceptionDto(
        recurringEventId: 99999,
        exceptionDate: '2025-02-10',
        isDeleted: true,
        replacementInstanceId: null,
        reason: null
    );

    $this->createAction->execute($this->user, $dto);
})->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

test('delete event exception action removes exception', function (): void {
    $exception = EventException::factory()->create([
        'recurring_event_id' => $this->recurring->id,
        'exception_date' => Carbon::parse('2025-02-10'),
    ]);

    $result = $this->deleteAction->execute($exception);

    expect($result)->toBeTrue()
        ->and(EventException::find($exception->id))->toBeNull();
});

test('update event exception action updates allowed attributes', function (): void {
    $exception = EventException::factory()->create([
        'recurring_event_id' => $this->recurring->id,
        'exception_date' => Carbon::parse('2025-02-10'),
        'is_deleted' => true,
        'reason' => null,
    ]);
    $dto = new UpdateEventExceptionDto(isDeleted: false, reason: 'Restored', replacementInstanceId: null);

    $updated = $this->updateAction->execute($exception, $dto);

    expect($updated->is_deleted)->toBeFalse()
        ->and($updated->reason)->toBe('Restored');
});

test('update event exception action returns fresh when no attributes', function (): void {
    $exception = EventException::factory()->create([
        'recurring_event_id' => $this->recurring->id,
        'exception_date' => Carbon::parse('2025-02-10'),
        'reason' => 'Original',
    ]);
    $dto = new UpdateEventExceptionDto(isDeleted: null, reason: null, replacementInstanceId: null);

    $updated = $this->updateAction->execute($exception, $dto);

    expect($updated->id)->toBe($exception->id)
        ->and($updated->reason)->toBe('Original');
});
