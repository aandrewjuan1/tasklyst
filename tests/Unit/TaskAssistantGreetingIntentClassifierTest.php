<?php

use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantGreetingIntentClassifier;
use App\Support\LLM\TaskAssistantReasonCodes;

test('classifier detects greeting-only hello there', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $result = app(TaskAssistantGreetingIntentClassifier::class)->classify($thread, 'hello there');

    expect($result['is_greeting_only'] ?? false)->toBeTrue();
    expect($result['reason_codes'] ?? [])->toContain(
        TaskAssistantReasonCodes::GREETING_ONLY_DETECTED,
        TaskAssistantReasonCodes::GREETING_SHORTCIRCUIT_GENERAL_GUIDANCE,
    );
});

test('classifier suppresses greeting-only when actionable cue exists', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $result = app(TaskAssistantGreetingIntentClassifier::class)->classify($thread, 'hello schedule my tasks');

    expect($result['is_greeting_only'] ?? true)->toBeFalse();
});
