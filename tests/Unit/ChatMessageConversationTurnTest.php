<?php

use App\DataTransferObjects\Llm\ConversationTurn;
use App\Enums\ChatMessageRole;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;

test('chat message converts to conversation turn dto', function (): void {
    $user = User::factory()->create();
    $thread = ChatThread::query()->create([
        'user_id' => $user->id,
        'title' => 'Test thread',
        'schema_version' => config('llm.schema_version'),
    ]);
    $createdAt = now();

    /** @var ChatMessage $message */
    $message = ChatMessage::query()->create([
        'thread_id' => $thread->id,
        'role' => ChatMessageRole::Assistant,
        'author_id' => $user->id,
        'content_text' => 'Hello',
        'content_json' => ['foo' => 'bar'],
        'meta' => [],
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    $turn = $message->toConversationTurn();

    expect($turn)->toBeInstanceOf(ConversationTurn::class);
    expect($turn->role)->toBe(ChatMessageRole::Assistant->value);
    expect($turn->text)->toBe('Hello');
    expect($turn->structured)->toBe(['foo' => 'bar']);
    expect($turn->createdAt->format('Y-m-d H:i:s'))->toBe($createdAt->format('Y-m-d H:i:s'));
});
