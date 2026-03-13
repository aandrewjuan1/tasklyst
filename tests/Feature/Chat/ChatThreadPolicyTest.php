<?php

use App\Models\ChatThread;
use App\Models\User;

test('owner can view update delete and send messages to thread', function (): void {
    $owner = User::factory()->create();

    $thread = ChatThread::query()->create([
        'user_id' => $owner->id,
        'title' => 'Thread',
        'schema_version' => config('llm.schema_version'),
    ]);

    expect($owner->can('view', $thread))->toBeTrue()
        ->and($owner->can('update', $thread))->toBeTrue()
        ->and($owner->can('delete', $thread))->toBeTrue()
        ->and($owner->can('sendMessage', $thread))->toBeTrue();
});

test('other user cannot view or send messages to thread', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $thread = ChatThread::query()->create([
        'user_id' => $owner->id,
        'title' => 'Thread',
        'schema_version' => config('llm.schema_version'),
    ]);

    expect($otherUser->can('view', $thread))->toBeFalse()
        ->and($otherUser->can('sendMessage', $thread))->toBeFalse();
});

test('soft deleted thread cannot be sent a message', function (): void {
    $owner = User::factory()->create();

    $thread = ChatThread::query()->create([
        'user_id' => $owner->id,
        'title' => 'Thread',
        'schema_version' => config('llm.schema_version'),
    ]);

    $thread->delete();

    expect($owner->can('sendMessage', $thread))->toBeFalse();
});
