<?php

use App\Actions\Task\ForceDeleteTaskAction;
use App\Enums\LlmToolCallStatus;
use App\Models\LlmToolCall;
use App\Models\Task;
use App\Models\User;
use App\Tools\LLM\TaskAssistant\ForceDeleteTaskTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns requires_confirm when confirm is not true', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['user_id' => $user->id]);
    $task->delete();
    $tool = new ForceDeleteTaskTool($user, app(ForceDeleteTaskAction::class));

    $result = $tool->__invoke(['taskId' => $task->id]);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeFalse();
    expect($decoded['requires_confirm'])->toBeTrue();
    expect(Task::withTrashed()->find($task->id))->not->toBeNull();
});

it('force deletes task and records tool call when confirm is true', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['user_id' => $user->id]);
    $task->delete();
    $taskId = $task->id;
    $tool = new ForceDeleteTaskTool($user, app(ForceDeleteTaskAction::class));

    $result = $tool->__invoke(['taskId' => $taskId, 'confirm' => true]);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeTrue();
    expect($decoded['task_id'])->toBe($taskId);
    expect(Task::withTrashed()->find($taskId))->toBeNull();
    $call = LlmToolCall::query()->where('tool_name', 'force_delete_task')->where('user_id', $user->id)->first();
    expect($call)->not->toBeNull();
    expect($call->status)->toBe(LlmToolCallStatus::Success);
});

it('returns cached result when same operation_token is used twice with confirm', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['user_id' => $user->id]);
    $task->delete();
    $tool = new ForceDeleteTaskTool($user, app(ForceDeleteTaskAction::class));
    $token = 'idempotency-'.uniqid();

    $result1 = $tool->__invoke(['taskId' => $task->id, 'confirm' => true, 'operation_token' => $token]);
    $task2 = Task::factory()->create(['user_id' => $user->id]);
    $task2->delete();
    $result2 = $tool->__invoke(['taskId' => $task2->id, 'confirm' => true, 'operation_token' => $token]);

    $decoded1 = json_decode($result1, true);
    $decoded2 = json_decode($result2, true);
    expect($decoded1['ok'])->toBeTrue();
    expect($decoded2['ok'])->toBeTrue();
    expect($decoded1['task_id'])->toBe($decoded2['task_id']);

    $calls = LlmToolCall::query()->where('operation_token', $token)->where('tool_name', 'force_delete_task')->get();
    expect($calls)->toHaveCount(1);
});

it('does not force delete task when called by another user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $task = Task::factory()->create(['user_id' => $userA->id]);
    $task->delete();
    $taskId = $task->id;
    $tool = new ForceDeleteTaskTool($userB, app(ForceDeleteTaskAction::class));

    $result = $tool->__invoke(['taskId' => $taskId, 'confirm' => true]);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeFalse();
    expect($decoded)->toHaveKey('error');
    expect(Task::withTrashed()->find($taskId))->not->toBeNull();
});
