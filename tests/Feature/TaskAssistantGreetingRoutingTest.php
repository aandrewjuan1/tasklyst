<?php

use App\Enums\MessageRole;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\IntentRoutingPolicy;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

test('pure greeting short-circuits to general guidance', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'task',
                'acknowledgement' => 'Thanks for saying hello.',
                'framing' => 'You are opening with a general task-support request.',
                'response' => 'I can help you get organized with your tasks.',
                'suggested_next_actions' => [
                    'Prioritize my tasks.',
                    'Schedule time blocks for my tasks.',
                ],
            ])
            ->withUsage(new Usage(1, 2))
            ->withMeta(new Meta('fake', 'fake')),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'hello',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('general_guidance');
    expect(data_get($assistantMessage->metadata, 'general_guidance.intent'))->toBe('task');
});

test('intent policy returns general_guidance for hello via greeting short-circuit', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'hello');

    expect($decision->flow)->toBe('general_guidance');
    expect($decision->reasonCodes)->toContain('greeting_shortcircuit_general_guidance');
});
