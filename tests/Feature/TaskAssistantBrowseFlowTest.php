<?php

use App\Enums\MessageRole;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

test('browse flow returns structured listing with hybrid narrative', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'listing',
                'confidence' => 0.95,
                'rationale' => 'User wants to see tasks.',
            ])
            ->withUsage(new Usage(1, 2)),
        StructuredResponseFake::make()
            ->withStructured([
                'summary' => 'Here is what matched.',
                'assistant_note' => null,
                'reasoning' => null,
                'strategy_points' => [],
                'suggested_next_steps' => ['Ask me to prioritize these.'],
                'assumptions' => [],
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Task::factory()->for($user)->count(2)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'List my tasks',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('browse');
    expect($assistantMessage->metadata['browse']['items'] ?? null)->toBeArray();
});
