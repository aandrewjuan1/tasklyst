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
                'message' => 'Right now, it is 3:45 PM for you.',
                'clarifying_question' => 'If you want, I can prioritize your tasks or schedule time blocks—which would you like?',
                'redirect_target' => 'either',
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
    expect(data_get($assistantMessage->metadata, 'general_guidance.redirect_target'))->toBe('either');
    expect((string) $assistantMessage->content)->toMatch('/\d{1,2}:\d{2}\s?(AM|PM)/i');

    // We don't hardcode exact wording because general guidance is LLM-generated.
    expect((string) $assistantMessage->content)->toContain('prioritize');
    expect((string) $assistantMessage->content)->toContain('schedule');
});

test('general guidance normalizes redirect_target "tasks" into a valid redirect', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'message' => 'I hear you.',
                'clarifying_question' => 'Do you want to prioritize or schedule?',
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
        'content' => 'help',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('general_guidance');
    expect(data_get($assistantMessage->metadata, 'general_guidance.redirect_target'))->toBe('either');
});
