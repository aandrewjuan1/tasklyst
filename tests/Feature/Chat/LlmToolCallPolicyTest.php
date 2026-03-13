<?php

use App\Models\ChatThread;
use App\Models\LlmToolCall;
use App\Models\User;

test('owner can view llm tool call', function (): void {
    $owner = User::factory()->create();

    $thread = ChatThread::query()->create([
        'user_id' => $owner->id,
        'title' => 'Thread',
        'schema_version' => config('llm.schema_version'),
    ]);

    $toolCall = LlmToolCall::query()->create([
        'client_request_id' => 'req-owner',
        'user_id' => $owner->id,
        'thread_id' => $thread->id,
        'tool' => 'create_task',
        'args_hash' => 'hash',
        'status' => 'pending',
    ]);

    expect($owner->can('view', $toolCall))->toBeTrue();
});

test('other user cannot view llm tool call', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $thread = ChatThread::query()->create([
        'user_id' => $owner->id,
        'title' => 'Thread',
        'schema_version' => config('llm.schema_version'),
    ]);

    $toolCall = LlmToolCall::query()->create([
        'client_request_id' => 'req-owner-2',
        'user_id' => $owner->id,
        'thread_id' => $thread->id,
        'tool' => 'create_task',
        'args_hash' => 'hash2',
        'status' => 'pending',
    ]);

    expect($otherUser->can('view', $toolCall))->toBeFalse();
});
