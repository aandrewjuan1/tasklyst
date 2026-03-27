<?php

use App\Enums\MessageRole;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

test('unclear general guidance keeps no pending guidance state', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'unclear',
                'acknowledgement' => "I didn't quite catch that yet.",
                'framing' => "Your message doesn't form a clear request yet.",
                'response' => 'Rephrase what you need and I will help you.',
                'suggested_next_actions' => [
                    'Prioritize my tasks.',
                    'Schedule time blocks for my tasks.',
                    'Tell me what to do first today.',
                ],
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage1 = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'hahawdakiodwak',
    ]);
    $assistantMessage1 = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage1->id, $assistantMessage1->id);

    $assistantMessage1->refresh();
    expect($assistantMessage1->metadata['structured']['flow'] ?? null)->toBe('general_guidance');
    expect(data_get($assistantMessage1->metadata, 'general_guidance.intent'))->toBe('unclear');

    $stateService = app(TaskAssistantConversationStateService::class);
    expect($stateService->pendingGeneralGuidance($thread))->toBeNull();
});

test('follow-up after unclear prompt is treated as a fresh message', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'unclear',
                'acknowledgement' => "I didn't quite catch that yet.",
                'framing' => "Your message doesn't form a clear request yet.",
                'response' => 'Rephrase what you need and I will help you.',
                'suggested_next_actions' => [
                    'Prioritize my tasks.',
                    'Schedule time blocks for my tasks.',
                    'Tell me what to do first today.',
                ],
            ])
            ->withUsage(new Usage(1, 2)),
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'task',
                'acknowledgement' => 'I hear you.',
                'framing' => 'You are asking for a concrete next planning action.',
                'response' => "Let's pick one immediate step you can execute now.",
                'suggested_next_actions' => [
                    'Prioritize my tasks.',
                    'Schedule time blocks for my tasks.',
                ],
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage1 = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'hahawdakiodwak',
    ]);
    $assistantMessage1 = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage1->id, $assistantMessage1->id);
    $assistantMessage1->refresh();
    expect($assistantMessage1->metadata['structured']['flow'] ?? null)->toBe('general_guidance');
    expect(data_get($assistantMessage1->metadata, 'general_guidance.intent'))->toBe('unclear');

    $userMessage2 = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'plan time blocks',
    ]);
    $assistantMessage2 = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage2->id, $assistantMessage2->id);

    $assistantMessage2->refresh();
    expect($assistantMessage2->metadata['structured']['flow'] ?? null)->toBe('general_guidance');
    expect(data_get($assistantMessage2->metadata, 'general_guidance.intent'))->toBe('task');

    $stateService = app(TaskAssistantConversationStateService::class);
    expect($stateService->pendingGeneralGuidance($thread))->toBeNull();
});
