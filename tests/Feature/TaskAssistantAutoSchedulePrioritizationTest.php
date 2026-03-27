<?php

use App\Enums\MessageRole;
use App\Enums\TaskComplexity;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

it('auto-schedules top task using prioritization logic when scheduling is explicit', function (): void {
    config()->set('task-assistant.intent.use_llm', true);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'scheduling',
                'confidence' => 0.95,
                'rationale' => 'User explicitly asked to schedule.',
            ])
            ->withUsage(new Usage(1, 1)),
        StructuredResponseFake::make()
            ->withStructured([
                'summary' => 'Focused schedule prepared.',
                'assistant_note' => 'Start with the highest urgency work first.',
                'reasoning' => 'This plan prioritizes your most urgent task.',
                'strategy_points' => ['Handle overdue work first.'],
                'suggested_next_steps' => ['Accept proposals to apply updates.'],
                'assumptions' => [],
            ])
            ->withUsage(new Usage(1, 1)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Task::factory()->for($user)->create([
        'title' => 'Impossible 5h study block before quiz',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Urgent,
        'complexity' => TaskComplexity::Complex,
        'start_datetime' => now()->subHours(8),
        'end_datetime' => now()->subHours(1),
        'duration' => 60,
    ]);
    Task::factory()->for($user)->create([
        'title' => 'Update CV and LinkedIn profile',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
        'complexity' => TaskComplexity::Moderate,
        'start_datetime' => null,
        'end_datetime' => now()->addDays(5),
        'duration' => 60,
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'schedule my top 1 task for later afternoon',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect(data_get($assistantMessage->metadata, 'structured.flow'))->toBe('schedule');
    $proposals = data_get($assistantMessage->metadata, 'schedule.proposals', []);
    expect($proposals)->toBeArray()->not->toBeEmpty();
    expect((string) data_get($proposals, '0.title'))->toContain('Impossible 5h study block before quiz');
});

it('auto-schedules urgent school task for school-focused direct scheduling prompt', function (): void {
    config()->set('task-assistant.intent.use_llm', true);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'scheduling',
                'confidence' => 0.95,
                'rationale' => 'User asked for scheduling a school task.',
            ])
            ->withUsage(new Usage(1, 1)),
        StructuredResponseFake::make()
            ->withStructured([
                'summary' => 'School schedule prepared.',
                'assistant_note' => 'You can start in a focused block.',
                'reasoning' => 'This sequence reflects urgent school work.',
                'strategy_points' => ['Prioritize urgent school deadlines.'],
                'suggested_next_steps' => ['Accept proposals to apply updates.'],
                'assumptions' => [],
            ])
            ->withUsage(new Usage(1, 1)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Task::factory()->for($user)->create([
        'title' => 'ITCS 101 - Midterm Project Checkpoint',
        'subject_name' => 'ITCS 101 - Intro to Programming',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Urgent,
        'complexity' => TaskComplexity::Complex,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
        'duration' => 240,
    ]);
    Task::factory()->for($user)->create([
        'title' => 'Wash dishes after dinner',
        'subject_name' => null,
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Low,
        'complexity' => TaskComplexity::Simple,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
        'duration' => 20,
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'plan my day around the most urgent school task',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect(data_get($assistantMessage->metadata, 'structured.flow'))->toBe('schedule');
    $proposals = data_get($assistantMessage->metadata, 'schedule.proposals', []);
    expect($proposals)->toBeArray()->not->toBeEmpty();
    expect((string) data_get($proposals, '0.title'))->toContain('ITCS 101 - Midterm Project Checkpoint');
});
