<?php

use App\Actions\Event\CreateEventAction;
use App\Enums\LlmToolCallStatus;
use App\Models\LlmToolCall;
use App\Models\User;
use App\Tools\TaskAssistant\CreateEventTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates an event and records tool call on happy path', function () {
    $user = User::factory()->create();
    $tool = new CreateEventTool($user, app(CreateEventAction::class));

    $result = $tool->__invoke(['title' => 'My event']);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeTrue();
    expect($decoded)->toHaveKey('event');
    expect($decoded['event']['title'])->toBe('My event');
    expect($decoded['event'])->toHaveKey('id');
    $call = LlmToolCall::query()->where('tool_name', 'create_event')->where('user_id', $user->id)->first();
    expect($call)->not->toBeNull();
    expect($call->status)->toBe(LlmToolCallStatus::Success);
});

it('returns cached result when same operation_token is used twice', function () {
    $user = User::factory()->create();
    $tool = new CreateEventTool($user, app(CreateEventAction::class));
    $token = 'idempotency-'.uniqid();

    $result1 = $tool->__invoke(['title' => 'Idempotent event', 'operation_token' => $token]);
    $result2 = $tool->__invoke(['title' => 'Idempotent event', 'operation_token' => $token]);

    $decoded1 = json_decode($result1, true);
    $decoded2 = json_decode($result2, true);
    expect($decoded1['ok'])->toBeTrue();
    expect($decoded2['ok'])->toBeTrue();
    expect($decoded1['event']['id'])->toBe($decoded2['event']['id']);
    $calls = LlmToolCall::query()->where('operation_token', $token)->where('tool_name', 'create_event')->get();
    expect($calls)->toHaveCount(1);
});
