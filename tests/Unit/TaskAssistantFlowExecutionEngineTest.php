<?php

use App\Enums\MessageRole;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantFlowExecutionEngine;

test('invalid prioritize execution returns safe minimal structured payload', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    $result = app(TaskAssistantFlowExecutionEngine::class)->executeStructuredFlow(
        flow: 'prioritize',
        metadataKey: 'prioritize',
        thread: $thread,
        assistantMessage: $assistantMessage,
        generationResult: [
            'valid' => false,
            'data' => [
                'items' => [
                    ['entity_type' => 'task', 'entity_id' => 999, 'title' => 'Invalid row'],
                ],
            ],
            'errors' => ['synthetic_generation_failure'],
        ],
        assistantFallbackContent: 'fallback content',
    );

    expect($result['final_valid'])->toBeFalse();
    expect($result['assistant_content'])->toBe('fallback content');
    expect($result['structured_data']['items'] ?? null)->toBe([]);
    expect($result['structured_data']['limit_used'] ?? null)->toBe(0);
    expect($result['structured_data']['next_options_chip_texts'] ?? null)->toBe([]);
});
