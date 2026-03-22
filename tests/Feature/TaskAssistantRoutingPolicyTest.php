<?php

use App\Enums\MessageRole;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

test('low composite margin triggers clarification flow', function (): void {
    config()->set('task-assistant.intent.merge.clarify_margin', 0.99);
    config()->set('task-assistant.intent.merge.clarify_composite_ceiling', 0.99);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'listing',
                'confidence' => 0.45,
                'rationale' => 'Ambiguous.',
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'tasks',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    $thread->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('clarify');
    expect(data_get($assistantMessage->metadata, 'clarification.needed'))->toBeTrue();
});

test('structured intent routes to schedule when LLM selects scheduling', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'scheduling',
                'confidence' => 0.95,
                'rationale' => 'User asked to plan the day.',
            ])
            ->withUsage(new Usage(1, 2)),
        StructuredResponseFake::make()
            ->withStructured([
                'summary' => 'Planned day overview.',
                'assistant_note' => 'Take breaks between blocks.',
                'reasoning' => 'Matches your afternoon preference.',
                'strategy_points' => ['Tackle hard tasks first.'],
                'suggested_next_steps' => ['Confirm blocks in calendar.'],
                'assumptions' => [],
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Schedule my day',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('schedule');
});
