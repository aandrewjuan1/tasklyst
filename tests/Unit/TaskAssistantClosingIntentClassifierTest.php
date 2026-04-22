<?php

use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantClosingIntentClassifier;
use App\Support\LLM\TaskAssistantReasonCodes;

test('classifier detects goodbye combo closing', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $result = app(TaskAssistantClosingIntentClassifier::class)->classify($thread, 'ok thanks bye');

    expect($result['is_closing'] ?? false)->toBeTrue();
    expect($result['reason_codes'] ?? [])->toContain(
        TaskAssistantReasonCodes::CLOSING_SHORTCIRCUIT_GENERAL_GUIDANCE
    );
});

test('classifier keeps actionable suppression reason code', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $result = app(TaskAssistantClosingIntentClassifier::class)->classify($thread, 'thanks, move it to 6pm');

    expect($result['is_closing'] ?? true)->toBeFalse();
    expect($result['reason_codes'] ?? [])->toContain(
        TaskAssistantReasonCodes::CLOSING_SUPPRESSED_ACTIONABLE_CUE
    );
});

test('short acknowledgement closes when planning context exists', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create([
        'user_id' => $user->id,
        'metadata' => [
            'conversation_state' => [
                'last_flow' => 'schedule',
            ],
        ],
    ]);

    $result = app(TaskAssistantClosingIntentClassifier::class)->classify($thread, 'ok');

    expect($result['is_closing'] ?? false)->toBeTrue();
    expect($result['reason_codes'] ?? [])->toContain(
        TaskAssistantReasonCodes::CLOSING_CONTEXT_WEIGHTED
    );
});
