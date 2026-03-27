<?php

use App\Enums\MessageRole;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

test('off-topic intent routes to general_guidance and injects guardrail instruction', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'off_topic',
                'confidence' => 0.9,
                'rationale' => 'Unrelated to task management',
            ])
            ->withUsage(new Usage(1, 2)),
        // Second structured call: general guidance generation.
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'out_of_scope',
                'acknowledgement' => 'Thanks for sharing your question.',
                'message' => "I can't help with that topic, but I can help you move forward with your tasks.",
                'suggested_next_actions' => [
                    'Prioritize my tasks.',
                    'Schedule time blocks for my tasks.',
                ],
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'who is the best president',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('general_guidance');
    expect(data_get($assistantMessage->metadata, 'general_guidance.intent'))->toBe('out_of_scope');
    expect(mb_strtolower((string) $assistantMessage->content))->toContain("can't help");
    expect($assistantMessage->content)->toContain('Prioritize my tasks.');
    expect($assistantMessage->content)->toContain('Schedule time blocks for my tasks.');

    $thread->refresh();
    expect(data_get($thread->metadata, 'conversation_state.pending_general_guidance'))->toBeNull();
});

test('off-topic heuristic still applies guardrail when llm intent inference is disabled', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        // general guidance generation should still be forced to off-topic mode.
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'unclear',
                'acknowledgement' => 'I understand your question.',
                'message' => 'The best keyboard right now is Cooledown.',
                'suggested_next_actions' => [
                    'Could you share your preference?',
                    'Prioritize my tasks.',
                    'Schedule time blocks for my tasks.',
                ],
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'whats the best keyboard right now',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('general_guidance');
    expect(data_get($assistantMessage->metadata, 'general_guidance.intent'))->toBe('out_of_scope');
    expect(mb_strtolower((string) $assistantMessage->content))->toContain("can't help");
    expect(mb_strtolower((string) $assistantMessage->content))->not->toContain('cooledown');
    expect(data_get($assistantMessage->metadata, 'validation_errors', []))->toBeEmpty();
});
