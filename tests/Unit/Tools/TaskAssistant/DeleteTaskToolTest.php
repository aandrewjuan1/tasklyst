<?php

use App\Actions\Task\DeleteTaskAction;
use App\Enums\LlmToolCallStatus;
use App\Models\LlmToolCall;
use App\Models\Task;
use App\Models\User;
use App\Tools\TaskAssistant\DeleteTaskTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns requires_confirm when confirm is not true', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['user_id' => $user->id]);
    $tool = new DeleteTaskTool($user, app(DeleteTaskAction::class));

    $result = $tool->__invoke(['taskId' => $task->id]);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeFalse();
    expect($decoded['requires_confirm'])->toBeTrue();
    $task->refresh();
    expect($task->trashed())->toBeFalse();
});

it('deletes task and records tool call when confirm is true', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['user_id' => $user->id]);
    $tool = new DeleteTaskTool($user, app(DeleteTaskAction::class));

    $result = $tool->__invoke(['taskId' => $task->id, 'confirm' => true]);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeTrue();
    expect($decoded['task_id'])->toBe($task->id);
    $task->refresh();
    expect($task->trashed())->toBeTrue();
    $call = LlmToolCall::query()->where('tool_name', 'delete_task')->where('user_id', $user->id)->first();
    expect($call)->not->toBeNull();
    expect($call->status)->toBe(LlmToolCallStatus::Success);
});

it('returns cached result when same operation_token is used twice with confirm', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['user_id' => $user->id]);
    $tool = new DeleteTaskTool($user, app(DeleteTaskAction::class));
    $token = 'idempotency-'.uniqid();

    $result1 = $tool->__invoke(['taskId' => $task->id, 'confirm' => true, 'operation_token' => $token]);
    $task2 = Task::factory()->create(['user_id' => $user->id]);
    $result2 = $tool->__invoke(['taskId' => $task2->id, 'confirm' => true, 'operation_token' => $token]);

    $decoded1 = json_decode($result1, true);
    $decoded2 = json_decode($result2, true);
    expect($decoded1['ok'])->toBeTrue();
    expect($decoded2['ok'])->toBeTrue();
    expect($decoded1['task_id'])->toBe($decoded2['task_id']);

    $calls = LlmToolCall::query()->where('operation_token', $token)->where('tool_name', 'delete_task')->get();
    expect($calls)->toHaveCount(1);
});

it('does not delete task when called by another user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $task = Task::factory()->create(['user_id' => $userA->id]);
    $tool = new DeleteTaskTool($userB, app(DeleteTaskAction::class));

    $result = $tool->__invoke(['taskId' => $task->id, 'confirm' => true]);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeFalse();
    expect($decoded)->toHaveKey('error');
    $task->refresh();
    expect($task->trashed())->toBeFalse();
});
