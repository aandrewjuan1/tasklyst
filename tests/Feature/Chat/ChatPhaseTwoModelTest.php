<?php

use App\Enums\ChatMessageRole;
use App\Enums\ToolCallStatus;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\LlmToolCall;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

test('phase 2 chat tables exist with expected columns', function (): void {
    expect(Schema::hasTable('chat_threads'))->toBeTrue()
        ->and(Schema::hasColumns('chat_threads', [
            'id',
            'user_id',
            'title',
            'model',
            'system_prompt',
            'schema_version',
            'metadata',
            'archived_at',
            'deleted_at',
            'created_at',
            'updated_at',
        ]))->toBeTrue();

    expect(Schema::hasTable('chat_messages'))->toBeTrue()
        ->and(Schema::hasColumns('chat_messages', [
            'id',
            'thread_id',
            'role',
            'author_id',
            'content_text',
            'content_json',
            'llm_raw',
            'meta',
            'client_request_id',
            'created_at',
            'updated_at',
        ]))->toBeTrue();

    expect(Schema::hasTable('llm_tool_calls'))->toBeTrue()
        ->and(Schema::hasColumns('llm_tool_calls', [
            'id',
            'client_request_id',
            'user_id',
            'thread_id',
            'tool',
            'args_hash',
            'tool_result_payload',
            'status',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

test('chat models cast and relate correctly', function (): void {
    $user = User::factory()->create();
    $thread = ChatThread::query()->create([
        'user_id' => $user->id,
        'title' => 'Prioritize today',
        'model' => 'hermes3:3b',
        'schema_version' => '2026-03-01.v1',
        'metadata' => ['source' => 'test'],
    ]);

    $message = ChatMessage::query()->create([
        'thread_id' => $thread->id,
        'role' => ChatMessageRole::Assistant,
        'author_id' => $user->id,
        'content_text' => 'Try this order.',
        'content_json' => ['intent' => 'prioritize'],
        'meta' => ['confidence' => 0.81],
    ]);

    $toolCall = LlmToolCall::query()->create([
        'client_request_id' => 'req-1',
        'user_id' => $user->id,
        'thread_id' => $thread->id,
        'tool' => 'create_task',
        'args_hash' => 'abc123',
        'tool_result_payload' => ['ok' => true],
        'status' => ToolCallStatus::Pending,
    ]);

    expect($thread->user->is($user))->toBeTrue()
        ->and($thread->messages()->count())->toBe(1)
        ->and($thread->toolCalls()->count())->toBe(1)
        ->and($thread->metadata)->toBe(['source' => 'test']);

    expect($message->role)->toBe(ChatMessageRole::Assistant)
        ->and($message->isAssistant())->toBeTrue()
        ->and($message->thread->is($thread))->toBeTrue()
        ->and($message->author->is($user))->toBeTrue();

    expect($toolCall->status)->toBe(ToolCallStatus::Pending)
        ->and($toolCall->thread->is($thread))->toBeTrue()
        ->and($toolCall->user->is($user))->toBeTrue()
        ->and(LlmToolCall::findByRequestId('req-1')?->id)->toBe($toolCall->id);
});

test('llm tool calls enforce client request id uniqueness', function (): void {
    $user = User::factory()->create();
    $thread = ChatThread::query()->create([
        'user_id' => $user->id,
        'title' => null,
        'model' => 'hermes3:3b',
        'schema_version' => '2026-03-01.v1',
    ]);

    LlmToolCall::query()->create([
        'client_request_id' => 'dup-req',
        'user_id' => $user->id,
        'thread_id' => $thread->id,
        'tool' => 'update_task',
        'args_hash' => 'hash-1',
        'status' => ToolCallStatus::Pending,
    ]);

    expect(fn () => LlmToolCall::query()->create([
        'client_request_id' => 'dup-req',
        'user_id' => $user->id,
        'thread_id' => $thread->id,
        'tool' => 'update_task',
        'args_hash' => 'hash-2',
        'status' => ToolCallStatus::Pending,
    ]))->toThrow(QueryException::class);
});
