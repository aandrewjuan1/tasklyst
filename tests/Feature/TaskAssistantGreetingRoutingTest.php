<?php

use App\Enums\MessageRole;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\IntentRoutingPolicy;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

test('pure greeting short-circuits to chat without intent LLM browse', function (): void {
    Prism::fake([
        TextResponseFake::make()
            ->withText('Hello! How can I help with your tasks today?')
            ->withFinishReason(FinishReason::Stop)
            ->withToolCalls([])
            ->withToolResults([])
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

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('chat');
});

test('intent policy returns chat for hello via greeting short-circuit', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'hello');

    expect($decision->flow)->toBe('chat');
    expect($decision->reasonCodes)->toContain('greeting_shortcircuit_chat');
});
