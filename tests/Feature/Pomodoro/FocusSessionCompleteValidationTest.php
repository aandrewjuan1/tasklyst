<?php

use App\Models\FocusSession;
use App\Models\User;
use App\Support\Validation\FocusSessionCompleteValidation;
use Illuminate\Support\Facades\Validator;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

function validCompletePayload(?int $focusSessionId = null, ?int $taskId = null, ?string $startedAt = null): array
{
    $payload = [
        'ended_at' => now()->toIso8601String(),
        'completed' => true,
        'paused_seconds' => 0,
    ];
    if ($focusSessionId !== null) {
        $payload['focus_session_id'] = $focusSessionId;
    }
    if ($taskId !== null) {
        $payload['task_id'] = $taskId;
    }
    if ($startedAt !== null) {
        $payload['started_at'] = $startedAt;
    }

    return $payload;
}

test('valid complete payload with focus_session_id passes', function (): void {
    $session = FocusSession::factory()->for($this->user)->create(['ended_at' => null]);
    $payload = validCompletePayload(focusSessionId: $session->id);

    $validator = Validator::make($payload, FocusSessionCompleteValidation::rules());

    expect($validator->passes())->toBeTrue();
});

test('complete payload fails when neither focus_session_id nor task_id plus started_at', function (): void {
    $payload = [
        'ended_at' => now()->toIso8601String(),
        'completed' => true,
        'paused_seconds' => 0,
    ];

    $validator = Validator::make($payload, FocusSessionCompleteValidation::rules());

    expect($validator->fails())->toBeTrue();
});

test('complete payload fails when completed is missing', function (): void {
    $session = FocusSession::factory()->for($this->user)->create();
    $payload = validCompletePayload(focusSessionId: $session->id);
    unset($payload['completed']);

    $validator = Validator::make($payload, FocusSessionCompleteValidation::rules());

    expect($validator->fails())->toBeTrue();
});

test('complete payload fails when paused_seconds is negative', function (): void {
    $session = FocusSession::factory()->for($this->user)->create();
    $payload = validCompletePayload(focusSessionId: $session->id);
    $payload['paused_seconds'] = -1;

    $validator = Validator::make($payload, FocusSessionCompleteValidation::rules());

    expect($validator->fails())->toBeTrue();
});

test('valid complete payload with mark_task_status passes', function (): void {
    $session = FocusSession::factory()->for($this->user)->create();
    $payload = validCompletePayload(focusSessionId: $session->id);
    $payload['mark_task_status'] = 'done';

    $validator = Validator::make($payload, FocusSessionCompleteValidation::rules());

    expect($validator->passes())->toBeTrue();
});

test('complete payload fails when mark_task_status is invalid', function (): void {
    $session = FocusSession::factory()->for($this->user)->create();
    $payload = validCompletePayload(focusSessionId: $session->id);
    $payload['mark_task_status'] = 'finished';

    $validator = Validator::make($payload, FocusSessionCompleteValidation::rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('mark_task_status'))->toBeTrue();
});
