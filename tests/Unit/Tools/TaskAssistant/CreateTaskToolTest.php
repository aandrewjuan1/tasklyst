<?php

use App\Actions\Task\CreateTaskAction;
use App\Enums\LlmToolCallStatus;
use App\Models\LlmToolCall;
use App\Models\User;
use App\Tools\TaskAssistant\CreateTaskTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a task and records tool call on happy path', function () {
    $user = User::factory()->create();
    $tool = new CreateTaskTool($user, app(CreateTaskAction::class));

    $result = $tool->__invoke(['title' => 'My new task']);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeTrue();
    expect($decoded)->toHaveKey('task');
    expect($decoded['task']['title'])->toBe('My new task');
    expect($decoded['task'])->toHaveKey('id');

    $call = LlmToolCall::query()->where('tool_name', 'create_task')->where('user_id', $user->id)->first();
    expect($call)->not->toBeNull();
    expect($call->status)->toBe(LlmToolCallStatus::Success);
    expect($call->result_json['ok'])->toBeTrue();
});

it('returns cached result when same operation_token is used twice', function () {
    $user = User::factory()->create();
    $tool = new CreateTaskTool($user, app(CreateTaskAction::class));
    $token = 'idempotency-'.uniqid();

    $result1 = $tool->__invoke(['title' => 'Idempotent task', 'operation_token' => $token]);
    $result2 = $tool->__invoke(['title' => 'Idempotent task', 'operation_token' => $token]);

    $decoded1 = json_decode($result1, true);
    $decoded2 = json_decode($result2, true);
    expect($decoded1['ok'])->toBeTrue();
    expect($decoded2['ok'])->toBeTrue();
    expect($decoded1['task']['id'])->toBe($decoded2['task']['id']);

    $calls = LlmToolCall::query()->where('operation_token', $token)->where('tool_name', 'create_task')->get();
    expect($calls)->toHaveCount(1);
});

it('records failed status when action throws', function () {
    $user = User::factory()->create();
    $action = \Mockery::mock(CreateTaskAction::class);
    $action->shouldReceive('execute')->andThrow(new \RuntimeException('Service unavailable'));
    $tool = new CreateTaskTool($user, $action);

    $result = $tool->__invoke(['title' => 'Will fail']);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeFalse();
    expect($decoded)->toHaveKey('error');

    $call = LlmToolCall::query()->where('tool_name', 'create_task')->where('user_id', $user->id)->first();
    expect($call)->not->toBeNull();
    expect($call->status)->toBe(LlmToolCallStatus::Failed);
});
