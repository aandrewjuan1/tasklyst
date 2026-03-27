<?php

use App\Enums\MessageRole;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

function introPrefix(): string
{
    return "Hi, I'm TaskLyst—your task assistant.";
}

test('general_guidance clamps content and avoids duplicating clarifying_question', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $longMessagePrefix = str_repeat('A', 480);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'task',
                'acknowledgement' => 'Thanks for reaching out.',
                'message' => 'Thanks for reaching out. '.$longMessagePrefix,
                'suggested_next_actions' => [
                    'Tell me what to do first today.',
                    'Prioritize my tasks.',
                    'Schedule time blocks for my tasks.',
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
    expect($assistantMessage->metadata['processed'] ?? null)->toBeTrue();
    expect($assistantMessage->metadata['validation_errors'] ?? [])->toBeEmpty();

    expect(str_contains((string) $assistantMessage->content, introPrefix()))->toBeTrue();
    expect(data_get($assistantMessage->metadata, 'general_guidance.intent'))->toBe('task');
    expect((string) $assistantMessage->content)->toContain('Next,');
    expect((string) $assistantMessage->content)->toContain('Prioritize my tasks.');
    expect((string) $assistantMessage->content)->toContain('Schedule time blocks for my tasks.');
    expect((string) $assistantMessage->content)->not->toContain('snapshot');
    expect((string) $assistantMessage->content)->not->toContain('JSON');
    expect((string) $assistantMessage->content)->not->toContain('backend');
});

test('general_guidance intro is only added once per thread', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'task',
                'acknowledgement' => 'Thanks for reaching out.',
                'message' => 'First guidance.',
                'suggested_next_actions' => [
                    'Prioritize my tasks.',
                    'Schedule time blocks for my tasks.',
                ],
            ])
            ->withUsage(new Usage(1, 2)),
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'task',
                'acknowledgement' => 'Thanks for reaching out again.',
                'message' => 'Second guidance.',
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
        'content' => 'help',
    ]);
    $assistantMessage1 = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);
    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage1->id, $assistantMessage1->id);
    $assistantMessage1->refresh();

    $userMessage2 = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'help again',
    ]);
    $assistantMessage2 = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);
    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage2->id, $assistantMessage2->id);
    $assistantMessage2->refresh();

    expect(str_contains((string) $assistantMessage1->content, introPrefix()))->toBeTrue();
    expect(str_contains((string) $assistantMessage2->content, introPrefix()))->toBeFalse();
});
