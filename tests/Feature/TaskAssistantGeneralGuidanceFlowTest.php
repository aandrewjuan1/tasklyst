<?php

use App\Enums\MessageRole;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

test('vague help prompt routes to task intent guidance without pending follow-up', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'task',
                'acknowledgement' => 'I hear you.',
                'message' => 'We can make this manageable one step at a time.',
                'suggested_next_actions' => [
                    'Prioritize my tasks.',
                    'Schedule time blocks for my tasks.',
                ],
                'next_options' => 'If you want, I can help you prioritize what to do first or schedule time for your most important work.',
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'help',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    $thread->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('general_guidance');
    expect(data_get($assistantMessage->metadata, 'general_guidance.intent'))->toBe('task');
    expect((string) data_get($assistantMessage->metadata, 'general_guidance.message'))->toContain('manageable');
    $actions = data_get($assistantMessage->metadata, 'general_guidance.suggested_next_actions', []);
    expect(is_array($actions))->toBeTrue();
    expect(implode(' ', $actions))->toContain('Prioritize');
    expect(implode(' ', $actions))->toContain('Schedule');
    expect(data_get($thread->metadata, 'conversation_state.pending_general_guidance'))->toBeNull();
});

test('pending guidance low-confidence retry uses latest user message instead of stale initial message', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'out_of_scope',
                'acknowledgement' => 'That is an interesting question.',
                'message' => "I can't help with that unrelated topic, but I can help you plan tasks.",
                'suggested_next_actions' => [
                    'Prioritize my tasks.',
                    'Schedule time blocks for my tasks.',
                ],
                'next_options' => 'If you want, I can help you prioritize what to do first or schedule time for your most important work.',
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $state = app(TaskAssistantConversationStateService::class);

    $state->rememberPendingGeneralGuidance(
        $thread,
        'asdkjzxqwe',
        'Can you rephrase your request in one short sentence?',
        ['gibberish_shortcircuit_general_guidance']
    );

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'who is the best ufc fighter of all time?',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $message = (string) data_get($assistantMessage->fresh()->metadata, 'general_guidance.message');
    expect($message)->not->toContain('asdkjzxqwe');
    expect($message)->toContain("I can't help with that");
    expect(data_get($thread->fresh()->metadata, 'conversation_state.pending_general_guidance'))->toBeNull();
});

test('gibberish guidance output uses unclear intent and stitched sections', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'unclear',
                'acknowledgement' => "I didn't quite catch that yet.",
                'message' => 'Rephrase what you need help with and I will guide you.',
                'suggested_next_actions' => [
                    'Prioritize my tasks.',
                    'Schedule time blocks for my tasks.',
                ],
                'next_options' => 'If you want, I can help you prioritize what to do first or schedule time for your most important work.',
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
    $assistantText = (string) $assistantMessage->content;
    expect(data_get($assistantMessage->metadata, 'general_guidance.intent'))->toBe('unclear');
    expect($assistantText)->toContain('If you want');
    expect(mb_strtolower($assistantText))->toContain('priorit');
    expect(mb_strtolower($assistantText))->toContain('schedule');
});

test('pending guidance does not hijack fresh standalone emotional off-topic prompt', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'out_of_scope',
                'acknowledgement' => "I'm sorry you're feeling this way.",
                'message' => "I can't help with personal relationship advice, but I can help you get unstuck with tasks.",
                'suggested_next_actions' => [
                    'Prioritize my tasks.',
                    'Schedule time blocks for my tasks.',
                ],
                'next_options' => 'If you want, I can help you prioritize what to do first or schedule time for your most important work.',
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $state = app(TaskAssistantConversationStateService::class);

    $state->rememberPendingGeneralGuidance(
        $thread,
        'asdkjzxqwe',
        'Can you rephrase your request in one short sentence?',
        ['gibberish_shortcircuit_general_guidance']
    );

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'my partner left me and i feel so sad',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    $thread->refresh();

    expect(data_get($assistantMessage->metadata, 'structured.flow'))->toBe('general_guidance');
    expect(data_get($assistantMessage->metadata, 'general_guidance.intent'))->toBe('out_of_scope');
    expect((string) $assistantMessage->content)->not->toContain('asdkjzxqwe');
    expect(data_get($thread->metadata, 'conversation_state.pending_general_guidance'))->toBeNull();
});
