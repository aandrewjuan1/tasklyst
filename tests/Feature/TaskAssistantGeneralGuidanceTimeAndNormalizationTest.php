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
                'guidance_mode' => 'friendly_general',
                'response' => 'Thanks for your time question. Right now, it is 3:45 PM for you.',
                'next_step_guidance' => 'If you want, I can prioritize your tasks or schedule time blocks next.',
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
        'content' => 'what is the current time right now?',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('general_guidance');
    expect(data_get($assistantMessage->metadata, 'general_guidance.guidance_mode'))->toBe('friendly_general');
    expect((string) $assistantMessage->content)->toMatch('/\d{1,2}:\d{2}\s?(AM|PM)/i');

    // We don't hardcode exact wording because general guidance is LLM-generated.
    expect((string) $assistantMessage->content)->toContain('tasks');
});

test('off-topic guidance keeps a normalized redirect target', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'guidance_mode' => 'off_topic',
                'response' => 'Thanks for sharing your request. I hear you.',
                'next_step_guidance' => 'I can help with your tasks by prioritizing them or planning time blocks.',
                'redirect_target' => 'tasks',
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
        'content' => 'best shoes',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('general_guidance');
    expect(data_get($assistantMessage->metadata, 'general_guidance.guidance_mode'))->toBe('off_topic');
    expect(data_get($assistantMessage->metadata, 'general_guidance.redirect_target'))->toBe('either');
});

test('gibberish prompt uses gibberish_unclear mode with clarifying question', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'guidance_mode' => 'gibberish_unclear',
                'response' => 'I did not fully understand that message. I can still help once you rephrase it clearly.',
                'next_step_guidance' => 'Please send one short sentence, then I can prioritize tasks or plan time blocks.',
                'clarifying_question' => 'Can you rephrase what you want help with in one short sentence?',
                'suggested_replies' => [
                    'Please prioritize my tasks.',
                    'Please schedule time blocks for my tasks.',
                ],
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

    expect(data_get($assistantMessage->metadata, 'general_guidance.guidance_mode'))->toBe('gibberish_unclear');
    expect(trim((string) data_get($assistantMessage->metadata, 'general_guidance.clarifying_question')))->not->toBe('');
    expect(data_get($thread->metadata, 'conversation_state.pending_general_guidance'))->not->toBeNull();
});
