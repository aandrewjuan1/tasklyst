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

test('invalid daily schedule execution reuses rich schedule narrative before generic fallback', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    $result = app(TaskAssistantFlowExecutionEngine::class)->executeStructuredFlow(
        flow: 'daily_schedule',
        metadataKey: 'schedule',
        thread: $thread,
        assistantMessage: $assistantMessage,
        generationResult: [
            'valid' => true,
            'data' => [
                'proposals' => [],
                'items' => [],
                'blocks' => [],
                'schedule_variant' => 'daily',
                'framing' => 'I could not fit this later today without creating a conflict.',
                'reasoning' => 'The remaining windows are shorter than the required duration.',
                'confirmation' => 'Would you like me to try tomorrow morning or pick another time window?',
                // Force a processor validation failure so execution enters fallback mode.
                'confirmation_required' => true,
                'confirmation_context' => [
                    'prompt' => '',
                    'options' => [],
                ],
            ],
            'errors' => [],
        ],
        assistantFallbackContent: 'I had trouble scheduling these items. Please try again with more details.',
    );

    expect($result['final_valid'])->toBeFalse();
    expect($result['assistant_content'])->toContain('I could not fit this later today without creating a conflict.');
    expect($result['assistant_content'])->toContain('Would you like me to try tomorrow morning or pick another time window?');
    expect($result['assistant_content'])->not->toBe('I had trouble scheduling these items. Please try again with more details.');
});

test('invalid prioritize execution reuses rich prioritize narrative before generic fallback', function (): void {
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
            'valid' => true,
            'data' => [
                'items' => [],
                // Force a processor validation failure for prioritize.
                'focus' => ['main_task' => 'Task A', 'secondary_tasks' => []],
                'framing' => 'You have one urgent item that stands out right now.',
                'reasoning' => 'Finishing it first will reduce pressure before your next deadline.',
                'next_options' => 'If you want, I can help you schedule it for later.',
            ],
            'errors' => [],
        ],
        assistantFallbackContent: 'I could not build a task list yet. Try again with a bit more detail.',
    );

    expect($result['final_valid'])->toBeFalse();
    expect($result['assistant_content'])->toContain('You have one urgent item that stands out right now.');
    expect($result['assistant_content'])->toContain('If you want, I can help you schedule it for later.');
    expect($result['assistant_content'])->not->toBe('I could not build a task list yet. Try again with a bit more detail.');
});
