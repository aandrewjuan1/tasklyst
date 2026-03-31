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

test('prioritize flow returns structured prioritized tasks with hybrid narrative', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'prioritization',
                'confidence' => 0.95,
                'rationale' => 'User wants to see tasks.',
            ])
            ->withUsage(new Usage(1, 2)),
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Start with the most urgent item first, then work down the list.',
                'acknowledgment' => null,
                'reasoning' => 'These tasks matched your filters.',
                'next_options' => 'If you want, I can schedule these steps for later.',
                'next_options_chip_texts' => ['Schedule these for later'],
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

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('prioritize');
    expect($assistantMessage->metadata['prioritize']['prioritize_variant'] ?? null)->toBeString();
    expect($assistantMessage->metadata['prioritize']['items'] ?? null)->toBeArray();
    expect($assistantMessage->metadata['prioritize']['next_options'] ?? null)->toContain('schedule');
    expect($assistantMessage->metadata['prioritize']['next_options_chip_texts'] ?? null)->toBeArray();
});

test('prioritize mismatch explanation does not claim explicit requested count when prompt has no number', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'prioritization',
                'confidence' => 0.95,
                'rationale' => 'User wants top tasks.',
            ])
            ->withUsage(new Usage(1, 2)),
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => null,
                'acknowledgment' => null,
                'count_mismatch_explanation' => 'While you asked for 3 tasks, only 1 is currently shown in this list. Let us focus on what you have already started today and then continue in y',
                'reasoning' => 'This task is overdue, so it is the clearest first move.',
                'next_options' => 'If you want, I can schedule this task for later today.',
                'next_options_chip_texts' => ['Later today'],
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Task::factory()->for($user)->create([
        'title' => 'Only matching today task',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Urgent,
        'start_datetime' => null,
        'end_datetime' => now()->subDay(),
    ]);

    Task::factory()->for($user)->create([
        'title' => 'Future task',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
        'start_datetime' => null,
        'end_datetime' => now()->addDays(8),
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'whats my top tasks that i need to do i wanna finish something today',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    $prioritize = is_array($assistantMessage->metadata['prioritize'] ?? null) ? $assistantMessage->metadata['prioritize'] : [];
    $mismatch = (string) ($prioritize['count_mismatch_explanation'] ?? '');
    $formatted = (string) $assistantMessage->content;

    expect($prioritize['items'] ?? [])->toHaveCount(1);
    expect($mismatch)->not->toContain('You asked for');
    expect(mb_strtolower($mismatch))->not->toContain('already started');
    expect($formatted)->toContain($mismatch);
});
