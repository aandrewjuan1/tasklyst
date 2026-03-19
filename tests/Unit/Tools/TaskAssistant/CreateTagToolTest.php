<?php

use App\Actions\Tag\CreateTagAction;
use App\Enums\LlmToolCallStatus;
use App\Models\LlmToolCall;
use App\Models\User;
use App\Tools\LLM\TaskAssistant\CreateTagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a tag and records tool call on happy path', function () {
    $user = User::factory()->create();
    $tool = new CreateTagTool($user, app(CreateTagAction::class));
    $result = $tool->__invoke(['name' => 'My tag']);
    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeTrue();
    expect($decoded)->toHaveKey('tag');
    expect($decoded['tag']['name'])->toBe('My tag');
    expect($decoded['tag'])->toHaveKey('id');
    $call = LlmToolCall::query()->where('tool_name', 'create_tag')->where('user_id', $user->id)->first();
    expect($call)->not->toBeNull();
    expect($call->status)->toBe(LlmToolCallStatus::Success);
});

it('returns cached result when same operation_token is used twice', function () {
    $user = User::factory()->create();
    $tool = new CreateTagTool($user, app(CreateTagAction::class));
    $token = 'idempotency-'.uniqid();
    $result1 = $tool->__invoke(['name' => 'Idempotent tag', 'operation_token' => $token]);
    $result2 = $tool->__invoke(['name' => 'Idempotent tag', 'operation_token' => $token]);
    $decoded1 = json_decode($result1, true);
    $decoded2 = json_decode($result2, true);
    expect($decoded1['ok'])->toBeTrue();
    expect($decoded2['ok'])->toBeTrue();
    expect($decoded1['tag']['id'])->toBe($decoded2['tag']['id']);
    $calls = LlmToolCall::query()->where('operation_token', $token)->where('tool_name', 'create_tag')->get();
    expect($calls)->toHaveCount(1);
});
