<?php

use App\Enums\MessageRole;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

test('general_guidance rewrites non-verb-led contextual action to avoid fallback', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'out_of_scope',
                'acknowledgement' => "That's an interesting question.",
                'message' => "I can't help with that topic, but I can help you move forward with your tasks.",
                'suggested_next_actions' => [
                    // This starts with "Could", which used to fail the validator.
                    'Could you share some details about what you need to do today?',
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
        'content' => 'whats the best keyboard right now',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    expect(data_get($assistantMessage->metadata, 'processed'))->toBeTrue();
    expect(data_get($assistantMessage->metadata, 'validation_errors', []))->toBeEmpty();
    expect((string) $assistantMessage->content)->not->toBe("Hi, I'm TaskLyst—your task assistant. Would you like me to prioritize your tasks or schedule time blocks for them?");
    expect(mb_strtolower((string) $assistantMessage->content))->toContain('tackle');
    expect(mb_strtolower((string) $assistantMessage->content))->toContain('calendar');
});
