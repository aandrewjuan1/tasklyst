<?php

use App\Actions\Task\RestoreTaskAction;
use App\Enums\LlmToolCallStatus;
use App\Models\LlmToolCall;
use App\Models\Task;
use App\Models\User;
use App\Tools\TaskAssistant\RestoreTaskTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('restores task and records tool call on happy path', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['user_id' => $user->id]);
    $task->delete();
    $tool = new RestoreTaskTool($user, app(RestoreTaskAction::class));

    $result = $tool->__invoke(['taskId' => $task->id]);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeTrue();
    expect($decoded['task_id'])->toBe($task->id);
    expect(Task::withTrashed()->find($task->id)->trashed())->toBeFalse();
    $call = LlmToolCall::query()->where('tool_name', 'restore_task')->where('user_id', $user->id)->first();
    expect($call)->not->toBeNull();
    expect($call->status)->toBe(LlmToolCallStatus::Success);
});

it('returns cached result when same operation_token is used twice', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['user_id' => $user->id]);
    $task->delete();
    $tool = new RestoreTaskTool($user, app(RestoreTaskAction::class));
    $token = 'idempotency-'.uniqid();

    $result1 = $tool->__invoke(['taskId' => $task->id, 'operation_token' => $token]);
    $result2 = $tool->__invoke(['taskId' => $task->id, 'operation_token' => $token]);

    $decoded1 = json_decode($result1, true);
    $decoded2 = json_decode($result2, true);
    expect($decoded1['ok'])->toBeTrue();
    expect($decoded2['ok'])->toBeTrue();
    expect($decoded1['task_id'])->toBe($decoded2['task_id']);

    $calls = LlmToolCall::query()->where('operation_token', $token)->where('tool_name', 'restore_task')->get();
    expect($calls)->toHaveCount(1);
});

it('does not restore task when called by another user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $task = Task::factory()->create(['user_id' => $userA->id]);
    $task->delete();
    $tool = new RestoreTaskTool($userB, app(RestoreTaskAction::class));

    $result = $tool->__invoke(['taskId' => $task->id]);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeFalse();
    expect($decoded)->toHaveKey('error');
    expect(Task::withTrashed()->find($task->id)->trashed())->toBeTrue();
});
