<?php

use App\Enums\MessageRole;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

it('routes greeting to friendly general guidance', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'guidance_mode' => 'friendly_general',
                'response' => 'Hello. I can help you manage your tasks.',
                'next_step_guidance' => 'I can prioritize your tasks or schedule time blocks for them. Which one should we start with first?',
                'suggested_replies' => ['Show my top tasks.', 'Schedule my day.'],
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'hey',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect(data_get($assistantMessage->metadata, 'structured.flow'))->toBe('general_guidance');
    expect(data_get($assistantMessage->metadata, 'general_guidance.guidance_mode'))->toBe('friendly_general');
    expect((string) data_get($assistantMessage->metadata, 'general_guidance.next_step_guidance'))->toContain('prioritize');
    expect((string) data_get($assistantMessage->metadata, 'general_guidance.next_step_guidance'))->toContain('schedule');
});

it('routes gibberish to gibberish unclear guidance with clarifying question', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'guidance_mode' => 'gibberish_unclear',
                'response' => "I didn't understand that yet.",
                'next_step_guidance' => 'I can prioritize your tasks or schedule time blocks when you are ready. Which one should we do first?',
                'clarifying_question' => 'Can you rephrase your request in one short sentence?',
                'suggested_replies' => ['Show my top tasks.', 'Schedule my day.'],
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'asdkjzxqwe',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect(data_get($assistantMessage->metadata, 'structured.flow'))->toBe('general_guidance');
    expect(data_get($assistantMessage->metadata, 'general_guidance.guidance_mode'))->toBe('gibberish_unclear');
    expect((string) data_get($assistantMessage->metadata, 'general_guidance.clarifying_question'))->toEndWith('?');
});

it('routes off-topic prompt to off-topic general guidance mode when llm labels off-topic', function (): void {
    config()->set('task-assistant.intent.use_llm', true);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'off_topic',
                'confidence' => 0.95,
                'rationale' => 'User asked for unrelated topic.',
            ])
            ->withUsage(new Usage(1, 2)),
        StructuredResponseFake::make()
            ->withStructured([
                'guidance_mode' => 'off_topic',
                'response' => "I can't help with unrelated topics.",
                'next_step_guidance' => 'I can prioritize your tasks or schedule time blocks for them. Which should we start with first?',
                'redirect_target' => 'either',
                'suggested_replies' => ['Prioritize my tasks.', 'Schedule my tasks.'],
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'who is the best ufc fighter of all time?',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect(data_get($assistantMessage->metadata, 'structured.flow'))->toBe('general_guidance');
    expect(data_get($assistantMessage->metadata, 'general_guidance.guidance_mode'))->toBe('off_topic');
    expect((string) data_get($assistantMessage->metadata, 'general_guidance.redirect_target'))->toBe('either');
});

it('routes time query to general guidance and includes current time context', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'guidance_mode' => 'friendly_general',
                'response' => "Thanks for asking. Right now, it's 2:30 PM for you.",
                'next_step_guidance' => 'I can prioritize your tasks or schedule time blocks for them. Which one should we do first?',
                'suggested_replies' => ['Show my top tasks.', 'Schedule my tasks.'],
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'what time is it right now?',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect(data_get($assistantMessage->metadata, 'structured.flow'))->toBe('general_guidance');
    expect(data_get($assistantMessage->metadata, 'general_guidance.guidance_mode'))->toBe('friendly_general');
    expect(mb_strtolower((string) $assistantMessage->content))->toContain('right now');
});
