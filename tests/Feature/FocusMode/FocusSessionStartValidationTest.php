<?php

use App\Models\Task;
use App\Models\User;
use App\Support\Validation\FocusSessionStartValidation;
use Illuminate\Support\Facades\Validator;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->task = Task::factory()->for($this->user)->create();
});

function validStartPayload(?int $taskId = null): array
{
    return [
        'task_id' => $taskId,
        'type' => 'work',
        'duration_seconds' => 1500,
        'started_at' => now()->toIso8601String(),
        'sequence_number' => 1,
        'payload' => [],
    ];
}

test('valid start payload passes validation', function (): void {
    $payload = validStartPayload($this->task->id);

    $validator = Validator::make($payload, FocusSessionStartValidation::rules());

    expect($validator->passes())->toBeTrue();
});

test('start payload without task_id passes for break types', function (): void {
    $payload = validStartPayload(null);
    $payload['type'] = 'short_break';

    $validator = Validator::make($payload, FocusSessionStartValidation::rules());

    expect($validator->passes())->toBeTrue();
});

test('start payload fails when type is invalid', function (): void {
    $payload = validStartPayload($this->task->id);
    $payload['type'] = 'invalid';

    $validator = Validator::make($payload, FocusSessionStartValidation::rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('type'))->toBeTrue();
});

test('start payload fails when duration_seconds is below min', function (): void {
    $payload = validStartPayload($this->task->id);
    $payload['duration_seconds'] = 30;

    $validator = Validator::make($payload, FocusSessionStartValidation::rules());

    expect($validator->fails())->toBeTrue();
});

test('start payload fails when started_at is missing', function (): void {
    $payload = validStartPayload($this->task->id);
    unset($payload['started_at']);

    $validator = Validator::make($payload, FocusSessionStartValidation::rules());

    expect($validator->fails())->toBeTrue();
});

test('start payload fails when task_id does not exist', function (): void {
    $payload = validStartPayload(99999);

    $validator = Validator::make($payload, FocusSessionStartValidation::rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('task_id'))->toBeTrue();
});
