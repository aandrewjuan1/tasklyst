<?php

use App\Enums\MessageRole;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

test('time query is answered deterministically and redirects to prioritize/schedule', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'task',
                'acknowledgement' => 'Thanks for asking.',
                'message' => 'Thanks for your time question. Right now, it is 3:45 PM for you.',
                'suggested_next_actions' => [
                    'Prioritize my tasks.',
                    'Schedule time blocks for my tasks.',
                ],
                'next_options' => 'If you want, I can help you prioritize what to tackle first or block time on your calendar for what matters most.',
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'what is the current time right now?',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('general_guidance');
    expect(data_get($assistantMessage->metadata, 'general_guidance.intent'))->toBe('task');
    expect((string) $assistantMessage->content)->toMatch('/\d{1,2}:\d{2}\s?(AM|PM)/i');

    // Closing paragraph (next_options) should still offer prioritize + schedule themes.
    expect(mb_strtolower((string) $assistantMessage->content))->toContain('tackle');
    expect(mb_strtolower((string) $assistantMessage->content))->toContain('calendar');
});

test('off-topic guidance stays in out_of_scope intent', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'out_of_scope',
                'acknowledgement' => 'Thanks for sharing.',
                'message' => "I can't help with that topic, but I can help you prioritize or schedule your tasks.",
                'suggested_next_actions' => [
                    'Prioritize my tasks.',
                    'Schedule time blocks for my tasks.',
                ],
                'next_options' => 'If you want, I can help you prioritize what to tackle first or block time on your calendar for what matters most.',
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'best shoes',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('general_guidance');
    expect(data_get($assistantMessage->metadata, 'general_guidance.intent'))->toBe('out_of_scope');
});

test('gibberish prompt uses unclear intent', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'unclear',
                'acknowledgement' => "I didn't quite catch that.",
                'message' => 'I did not fully understand that message yet. Please rephrase it in one short sentence.',
                'suggested_next_actions' => [
                    'Prioritize my tasks.',
                    'Schedule time blocks for my tasks.',
                ],
                'next_options' => 'If you want, I can help you prioritize what to tackle first or block time on your calendar for what matters most.',
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'hahawdakiodwak',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);
    $assistantMessage->refresh();
    $thread->refresh();

    expect(data_get($assistantMessage->metadata, 'general_guidance.intent'))->toBe('unclear');
    expect(data_get($thread->metadata, 'conversation_state.pending_general_guidance'))->toBeNull();
});
