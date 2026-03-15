<?php

use App\Actions\Task\UpdateTaskPropertyAction;
use App\Enums\LlmToolCallStatus;
use App\Models\LlmToolCall;
use App\Models\Task;
use App\Models\User;
use App\Tools\TaskAssistant\UpdateTaskTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('updates task and records tool call on happy path', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['user_id' => $user->id, 'title' => 'Original']);
    $tool = new UpdateTaskTool($user, app(UpdateTaskPropertyAction::class));

    $result = $tool->__invoke(['taskId' => $task->id, 'property' => 'title', 'value' => 'Updated']);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeTrue();
    expect($decoded['task']['title'])->toBe('Updated');
    $task->refresh();
    expect($task->title)->toBe('Updated');
    $call = LlmToolCall::query()->where('tool_name', 'update_task')->where('user_id', $user->id)->first();
    expect($call)->not->toBeNull();
    expect($call->status)->toBe(LlmToolCallStatus::Success);
});

it('returns cached result when same operation_token is used twice', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['user_id' => $user->id]);
    $tool = new UpdateTaskTool($user, app(UpdateTaskPropertyAction::class));
    $token = 'idempotency-'.uniqid();

    $result1 = $tool->__invoke(['taskId' => $task->id, 'property' => 'title', 'value' => 'First', 'operation_token' => $token]);
    $result2 = $tool->__invoke(['taskId' => $task->id, 'property' => 'title', 'value' => 'Second', 'operation_token' => $token]);

    $decoded1 = json_decode($result1, true);
    $decoded2 = json_decode($result2, true);
    expect($decoded1['ok'])->toBeTrue();
    expect($decoded2['ok'])->toBeTrue();
    $task->refresh();
    expect($task->title)->toBe('First');

    $calls = LlmToolCall::query()->where('operation_token', $token)->where('tool_name', 'update_task')->get();
    expect($calls)->toHaveCount(1);
});

it('does not update task when called by another user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $task = Task::factory()->create(['user_id' => $userA->id, 'title' => 'Original']);
    $tool = new UpdateTaskTool($userB, app(UpdateTaskPropertyAction::class));

    $result = $tool->__invoke(['taskId' => $task->id, 'property' => 'title', 'value' => 'Hacked']);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeFalse();
    expect($decoded)->toHaveKey('error');
    $task->refresh();
    expect($task->title)->toBe('Original');
});
