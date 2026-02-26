<?php

use App\Actions\Llm\AppendAssistantMessageAction;
use App\Actions\Llm\GetOrCreateAssistantThreadAction;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->getOrCreateAction = app(GetOrCreateAssistantThreadAction::class);
    $this->appendAction = app(AppendAssistantMessageAction::class);
});

test('get or create creates new thread when thread id is null', function (): void {
    $thread = $this->getOrCreateAction->execute($this->user, null);

    expect($thread)->toBeInstanceOf(AssistantThread::class)
        ->and($thread->user_id)->toBe($this->user->id)
        ->and($thread->title)->toBeNull()
        ->and($thread->id)->not->toBeNull();
});

test('get or create returns existing thread when thread id belongs to user', function (): void {
    $existing = AssistantThread::factory()->for($this->user)->create();

    $thread = $this->getOrCreateAction->execute($this->user, $existing->id);

    expect($thread->id)->toBe($existing->id)
        ->and(AssistantThread::count())->toBe(1);
});

test('get or create creates new thread when thread id does not belong to user', function (): void {
    $otherUser = User::factory()->create();
    $otherThread = AssistantThread::factory()->for($otherUser)->create();

    $thread = $this->getOrCreateAction->execute($this->user, $otherThread->id);

    expect($thread->user_id)->toBe($this->user->id)
        ->and($thread->id)->not->toBe($otherThread->id)
        ->and(AssistantThread::count())->toBe(2);
});

test('append message persists user message and touches thread', function (): void {
    $thread = $this->getOrCreateAction->execute($this->user, null);
    $beforeUpdatedAt = $thread->updated_at;

    $message = $this->appendAction->execute($thread, 'user', 'What should I do today?', []);

    expect($message)->toBeInstanceOf(AssistantMessage::class)
        ->and($message->role)->toBe('user')
        ->and($message->content)->toBe('What should I do today?')
        ->and($message->metadata)->toBeNull()
        ->and($message->assistant_thread_id)->toBe($thread->id);
    $thread->refresh();
    expect($thread->updated_at->gte($beforeUpdatedAt))->toBeTrue();
});

test('append message persists assistant message with metadata', function (): void {
    $thread = $this->getOrCreateAction->execute($this->user, null);
    $metadata = [
        'intent' => 'prioritize_tasks',
        'entity_type' => 'task',
        'confidence' => 0.9,
    ];

    $message = $this->appendAction->execute($thread, 'assistant', 'Here are your top tasks.', $metadata);

    expect($message->role)->toBe('assistant')
        ->and($message->content)->toBe('Here are your top tasks.')
        ->and($message->metadata)->toBe($metadata);
});

test('thread messages are ordered by created_at', function (): void {
    $thread = $this->getOrCreateAction->execute($this->user, null);
    $this->appendAction->execute($thread, 'user', 'First');
    $this->appendAction->execute($thread, 'assistant', 'Reply one');
    $this->appendAction->execute($thread, 'user', 'Second');

    $messages = $thread->messages()->get();

    expect($messages->pluck('content')->all())->toBe(['First', 'Reply one', 'Second']);
});

test('lastMessages returns last N in chronological order', function (): void {
    $thread = $this->getOrCreateAction->execute($this->user, null);
    $this->appendAction->execute($thread, 'user', 'One');
    $this->appendAction->execute($thread, 'assistant', 'Two');
    $this->appendAction->execute($thread, 'user', 'Three');
    $this->appendAction->execute($thread, 'assistant', 'Four');
    $this->appendAction->execute($thread, 'user', 'Five');

    $last = $thread->lastMessages(3);

    expect($last->pluck('content')->all())->toBe(['Three', 'Four', 'Five']);
});

test('assistant message has no updated_at column', function (): void {
    $thread = $this->getOrCreateAction->execute($this->user, null);
    $message = $this->appendAction->execute($thread, 'user', 'Test', []);

    expect($message->getAttributes())->not->toHaveKey('updated_at');
});

test('user has assistant threads relationship', function (): void {
    AssistantThread::factory()->for($this->user)->count(2)->create();

    expect($this->user->assistantThreads)->toHaveCount(2);
});
