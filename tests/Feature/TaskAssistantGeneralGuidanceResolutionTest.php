<?php

use App\Enums\MessageRole;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

test('general guidance resolves to prioritize', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'guidance_mode' => 'gibberish_unclear',
                'acknowledgement' => 'I did not fully understand your last message.',
                'message' => 'I can still help once you rephrase it.',
                'next_step_guidance' => 'Please rephrase your request, then I can prioritize tasks or plan time blocks.',
                'clarifying_question' => 'Do you want me to show your top tasks, or help plan time blocks for them?',
                'suggested_replies' => [
                    'Prioritize my tasks.',
                    'Plan time blocks for my tasks.',
                ],
            ])
            ->withUsage(new Usage(1, 2)),
        StructuredResponseFake::make()
            ->withStructured([
                'target' => 'prioritize',
                'confidence' => 0.9,
                'rationale' => 'User explicitly asked to prioritize tasks.',
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

    $userMessage2 = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'prioritize my tasks',
    ]);
    $assistantMessage2 = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage2->id, $assistantMessage2->id);

    $assistantMessage2->refresh();
    expect($assistantMessage2->metadata['structured']['flow'] ?? null)->toBe('prioritize');

    $stateService = app(TaskAssistantConversationStateService::class);
    expect($stateService->pendingGeneralGuidance($thread))->toBeNull();
});

test('general guidance resolves to prioritize even for “prioritizing” answer', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'guidance_mode' => 'gibberish_unclear',
                'acknowledgement' => 'I did not fully understand your last message.',
                'message' => 'I can still help once you rephrase it.',
                'next_step_guidance' => 'Please rephrase your request, then I can prioritize tasks or plan time blocks.',
                'clarifying_question' => 'Do you want me to show your top tasks, or help plan time blocks for them?',
                'suggested_replies' => [
                    'Prioritize my tasks.',
                    'Plan time blocks for my tasks.',
                ],
            ])
            ->withUsage(new Usage(1, 2)),
        // target selection returns a variant label that must normalize to `prioritize`.
        StructuredResponseFake::make()
            ->withStructured([
                'target' => 'prioritizing',
                'confidence' => 0.9,
                'rationale' => 'User chose prioritizing.',
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

    $userMessage2 = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'lets do prioritizing',
    ]);
    $assistantMessage2 = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage2->id, $assistantMessage2->id);
    $assistantMessage2->refresh();

    expect($assistantMessage2->metadata['structured']['flow'] ?? null)->toBe('prioritize');

    $stateService = app(TaskAssistantConversationStateService::class);
    expect($stateService->pendingGeneralGuidance($thread))->toBeNull();
});

test('general guidance resolves to schedule', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'guidance_mode' => 'gibberish_unclear',
                'acknowledgement' => 'I did not fully understand your last message.',
                'message' => 'I can still help once you rephrase it.',
                'next_step_guidance' => 'Please rephrase your request, then I can prioritize tasks or plan time blocks.',
                'clarifying_question' => 'Do you want me to show your top tasks, or help plan time blocks for them?',
                'suggested_replies' => [
                    'Prioritize my tasks.',
                    'Plan time blocks for my tasks.',
                ],
            ])
            ->withUsage(new Usage(1, 2)),
        StructuredResponseFake::make()
            ->withStructured([
                'target' => 'schedule',
                'confidence' => 0.92,
                'rationale' => 'User asked for time blocks.',
            ])
            ->withUsage(new Usage(1, 2)),
        // schedule narrative refinement (daily_schedule_refinement schema)
        StructuredResponseFake::make()
            ->withStructured([])
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
    expect($assistantMessage2->metadata['structured']['flow'] ?? null)->toBe('schedule');

    $stateService = app(TaskAssistantConversationStateService::class);
    expect($stateService->pendingGeneralGuidance($thread))->toBeNull();
});

test('low-confidence target selection re-asks general guidance', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        // initial guidance generation
        StructuredResponseFake::make()
            ->withStructured([
                'guidance_mode' => 'gibberish_unclear',
                'acknowledgement' => 'I did not fully understand your last message.',
                'message' => 'I can still help once you rephrase it.',
                'next_step_guidance' => 'Please rephrase your request, then I can prioritize tasks or plan time blocks.',
                'clarifying_question' => 'Do you want me to show your top tasks, or help plan time blocks for them?',
                'suggested_replies' => [
                    'Prioritize my tasks.',
                    'Plan time blocks for my tasks.',
                ],
            ])
            ->withUsage(new Usage(1, 2)),
        // target selection (low confidence)
        StructuredResponseFake::make()
            ->withStructured([
                'target' => 'either',
                'confidence' => 0.3,
                'rationale' => 'Not clear which one the user wants.',
            ])
            ->withUsage(new Usage(1, 2)),
        // re-ask generation (should keep same question; we provide it in payload)
        StructuredResponseFake::make()
            ->withStructured([
                'guidance_mode' => 'gibberish_unclear',
                'acknowledgement' => 'Thanks for the follow-up.',
                'message' => 'I still need one clearer sentence.',
                'next_step_guidance' => 'Once you clarify, I can prioritize tasks or schedule time blocks for you.',
                'clarifying_question' => 'Do you want me to show your top tasks, or help plan time blocks for them?',
                'suggested_replies' => [
                    'Prioritize my tasks.',
                    'Plan time blocks for my tasks.',
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

    $userMessage2 = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'i guess so',
    ]);
    $assistantMessage2 = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage2->id, $assistantMessage2->id);
    $assistantMessage2->refresh();
    expect($assistantMessage2->metadata['structured']['flow'] ?? null)->toBe('general_guidance');

    $stateService = app(TaskAssistantConversationStateService::class);
    expect($stateService->pendingGeneralGuidance($thread))->not->toBeNull();
});
