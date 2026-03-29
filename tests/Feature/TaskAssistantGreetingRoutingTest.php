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
                'acknowledgement' => "I understand you're looking for suggestions on what tasks to tackle next.",
                'message' => "Based on your list data, one task is 'Review meeting notes for [CLIENT] project'.",
                'suggested_next_actions' => [
                    'Create a new task titled "Review meeting notes for [CLIENT] project" if it\'s not already created.',
                    'Prioritize my tasks.',
                    'Schedule time blocks for my tasks.',
                ],
                'next_options' => 'If you want, I can help you prioritize what to tackle first or block time on your calendar for what matters most.',
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
    expect((string) $assistantMessage->content)->toContain("Hi, I'm TaskLyst—your task assistant.");
    expect(mb_strtolower((string) $assistantMessage->content))->toContain('tackle');
    expect(mb_strtolower((string) $assistantMessage->content))->toContain('calendar');
    expect(mb_strtolower((string) $assistantMessage->content))->not->toContain('based on your list');
    expect(mb_strtolower((string) $assistantMessage->content))->not->toContain('your list data');
    expect(mb_strtolower((string) $assistantMessage->content))->not->toContain('create a new task');
});

test('intent policy returns general_guidance for hello via greeting short-circuit', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'hello');

    expect($decision->flow)->toBe('general_guidance');
    expect($decision->reasonCodes)->toContain('greeting_shortcircuit_general_guidance');
    expect($decision->reasonCodes)->toContain('general_guidance_greeting_only');
});
