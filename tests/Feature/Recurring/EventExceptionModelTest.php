<?php

use App\Models\EventException;
use App\Models\EventInstance;
use App\Models\RecurringEvent;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('event exception belongs to recurring event', function (): void {
    $recurring = RecurringEvent::factory()->create();
    $exception = EventException::factory()->create(['recurring_event_id' => $recurring->id]);

    expect($exception->recurringEvent)->not->toBeNull()
        ->and($exception->recurringEvent->id)->toBe($recurring->id);
});

test('event exception belongs to replacement instance when set', function (): void {
    $recurring = RecurringEvent::factory()->create();
    $instance = EventInstance::factory()->create(['recurring_event_id' => $recurring->id]);
    $exception = EventException::factory()->create([
        'recurring_event_id' => $recurring->id,
        'replacement_instance_id' => $instance->id,
    ]);

    expect($exception->replacementInstance)->not->toBeNull()
        ->and($exception->replacementInstance->id)->toBe($instance->id);
});

test('event exception casts exception date to date', function (): void {
    $exception = EventException::factory()->create(['exception_date' => '2025-02-10']);

    expect($exception->exception_date)->toBeInstanceOf(\Carbon\CarbonInterface::class)
        ->and($exception->exception_date->format('Y-m-d'))->toBe('2025-02-10');
});

test('event exception casts is deleted to boolean', function (): void {
    $exception = EventException::factory()->create(['is_deleted' => true]);

    expect($exception->is_deleted)->toBeTrue();
});

test('event exception belongs to created by user', function (): void {
    $exception = EventException::factory()->create(['created_by' => $this->user->id]);

    expect($exception->createdBy)->not->toBeNull()
        ->and($exception->createdBy->id)->toBe($this->user->id);
});
