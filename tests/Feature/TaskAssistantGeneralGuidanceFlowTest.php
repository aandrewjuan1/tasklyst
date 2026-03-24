<?php

use App\Enums\MessageRole;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

test('vague help prompt routes to friendly general guidance without pending follow-up', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'guidance_mode' => 'friendly_general',
                'acknowledgement' => 'Thanks for reaching out.',
                'message' => 'I hear you. We can make this manageable.',
                'next_step_guidance' => 'Start with task 77 "Biology worksheet", then task 91 "Lab summary".',
                'suggested_replies' => [
                    'Show my top tasks.',
                    'Plan time blocks for my tasks.',
                ],
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
    expect(data_get($assistantMessage->metadata, 'general_guidance.guidance_mode'))->toBe('friendly_general');
    expect((string) data_get($assistantMessage->metadata, 'general_guidance.next_step_guidance'))->toContain('If you want, I can');
    expect(data_get($thread->metadata, 'conversation_state.pending_general_guidance'))->toBeNull();
});
