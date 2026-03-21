<?php

use App\Enums\MessageRole;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantService;

test('policy routing triggers clarification flow for ambiguous execution confidence', function (): void {
    config()->set('task-assistant.routing.policy_enabled', true);
    config()->set('task-assistant.routing.execute_threshold', 0.9);
    config()->set('task-assistant.routing.clarify_threshold', 0.45);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Schedule my day',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    $thread->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('clarify');
    expect(data_get($assistantMessage->metadata, 'clarification.needed'))->toBeTrue();
    expect(data_get($thread->metadata, 'conversation_state.pending_clarification.target_flow'))->toBe('schedule');
});

test('legacy routing remains active when policy flag is disabled', function (): void {
    config()->set('task-assistant.routing.policy_enabled', false);
    config()->set('task-assistant.routing.execute_threshold', 0.9);
    config()->set('task-assistant.routing.clarify_threshold', 0.45);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Schedule my day',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('schedule');
});
