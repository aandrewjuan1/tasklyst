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
                'message' => "I can't help with that topic. I'm a task assistant.",
                'clarifying_question' => 'If you want, I can prioritize your tasks or schedule time blocks—which would you like?',
                'redirect_target' => 'either',
                'suggested_replies' => [
                    'Prioritize my tasks.',
                    'Plan time blocks for my tasks.',
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
    expect($assistantMessage->content)->toContain("I'm a task assistant");
    expect($assistantMessage->content)->toContain('prioritize your tasks');
    expect($assistantMessage->content)->toContain('schedule time blocks');

    $thread->refresh();
    $reasonCodes = data_get($thread->metadata, 'conversation_state.pending_general_guidance.reason_codes', []);
    expect(is_array($reasonCodes))->toBeTrue();
    expect($reasonCodes)->toContain('intent_off_topic');
});
