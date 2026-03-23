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

    $clarifyingQuestion = 'Would you like me to prioritize your tasks or schedule time blocks for them?';
    $longMessagePrefix = str_repeat('A', 520);
    $messageFromHermes = $longMessagePrefix.' '.$clarifyingQuestion;

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'message' => $messageFromHermes,
                'clarifying_question' => $clarifyingQuestion,
                'redirect_target' => 'either',
                'suggested_replies' => [
                    'Short reply.',
                    str_repeat('B', 250),
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

    expect(str_starts_with((string) $assistantMessage->content, introPrefix()))->toBeTrue();
    expect(substr_count((string) $assistantMessage->content, $clarifyingQuestion))->toBe(1);
});

test('general_guidance intro is only added once per thread', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'message' => 'First guidance.',
                'clarifying_question' => 'Do you want me to show your top tasks, or help plan time blocks for them?',
                'redirect_target' => 'either',
                'suggested_replies' => null,
            ])
            ->withUsage(new Usage(1, 2)),
        StructuredResponseFake::make()
            ->withStructured([
                'message' => 'Second guidance.',
                'clarifying_question' => 'Do you want me to show your top tasks, or help plan time blocks for them?',
                'redirect_target' => 'either',
                'suggested_replies' => null,
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

    expect(str_starts_with((string) $assistantMessage1->content, introPrefix()))->toBeTrue();
    expect(str_starts_with((string) $assistantMessage2->content, introPrefix()))->toBeFalse();
});
