<?php

use App\Actions\Project\CreateProjectAction;
use App\Enums\LlmToolCallStatus;
use App\Models\LlmToolCall;
use App\Models\User;
use App\Tools\LLM\TaskAssistant\CreateProjectTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a project and records tool call on happy path', function () {
    $user = User::factory()->create();
    $tool = new CreateProjectTool($user, app(CreateProjectAction::class));

    $result = $tool->__invoke(['name' => 'My project']);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeTrue();
    expect($decoded)->toHaveKey('project');
    expect($decoded['project']['name'])->toBe('My project');
    expect($decoded['project'])->toHaveKey('id');
    $call = LlmToolCall::query()->where('tool_name', 'create_project')->where('user_id', $user->id)->first();
    expect($call)->not->toBeNull();
    expect($call->status)->toBe(LlmToolCallStatus::Success);
});

it('returns cached result when same operation_token is used twice', function () {
    $user = User::factory()->create();
    $tool = new CreateProjectTool($user, app(CreateProjectAction::class));
    $token = 'idempotency-'.uniqid();

    $result1 = $tool->__invoke(['name' => 'Idempotent project', 'operation_token' => $token]);
    $result2 = $tool->__invoke(['name' => 'Idempotent project', 'operation_token' => $token]);

    $decoded1 = json_decode($result1, true);
    $decoded2 = json_decode($result2, true);
    expect($decoded1['ok'])->toBeTrue();
    expect($decoded2['ok'])->toBeTrue();
    expect($decoded1['project']['id'])->toBe($decoded2['project']['id']);
    $calls = LlmToolCall::query()->where('operation_token', $token)->where('tool_name', 'create_project')->get();
    expect($calls)->toHaveCount(1);
});
