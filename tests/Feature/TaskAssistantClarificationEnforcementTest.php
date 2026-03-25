<?php

use App\Enums\MessageRole;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

test('clarification answer enforces prioritize->schedule', function (): void {
    config()->set('task-assistant.intent.merge.clarify_margin', 0.99);
    config()->set('task-assistant.intent.merge.clarify_composite_ceiling', 0.99);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'prioritization',
                'confidence' => 0.45,
                'rationale' => 'Ambiguous.',
            ])
            ->withUsage(new Usage(1, 2)),
        // schedule narrative refinement (schedule_narrative_refinement)
        StructuredResponseFake::make()
            ->withStructured([])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();

    $tasks = Task::factory()
        ->for($user)
        ->count(3)
        ->create([
            'status' => TaskStatus::ToDo,
            'priority' => TaskPriority::High,
            'start_datetime' => null,
            'end_datetime' => now()->addDay(),
            'duration' => 45,
        ]);

    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $stateService = app(TaskAssistantConversationStateService::class);

    $items = $tasks->map(static fn (Task $task): array => [
        'entity_type' => 'task',
        'entity_id' => $task->id,
        'title' => (string) $task->title,
    ])->values()->all();

    $stateService->rememberPrioritizedItems($thread, $items, 3);

    $userMessage1 = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'tasks',
    ]);
    $assistantMessage1 = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage1->id, $assistantMessage1->id);

    $assistantMessage1->refresh();
    $thread->refresh();

    expect($assistantMessage1->metadata['structured']['flow'] ?? null)->toBe('clarify');
    expect($stateService->pendingClarification($thread))->not->toBeNull();

    $userMessage2 = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'schedule those 3 tasks for later afternoon',
    ]);
    $assistantMessage2 = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage2->id, $assistantMessage2->id);

    $assistantMessage2->refresh();
    $thread->refresh();

    expect($assistantMessage2->metadata['structured']['flow'] ?? null)->toBe('schedule');

    $lastSchedule = data_get($thread->metadata, 'conversation_state.last_schedule');
    expect(is_array($lastSchedule))->toBeTrue();

    $targetEntities = data_get($lastSchedule, 'target_entities', []);
    expect(is_array($targetEntities))->toBeTrue();
    expect($targetEntities)->toHaveCount(3);
    expect(array_column($targetEntities, 'entity_id'))->toEqual($tasks->pluck('id')->values()->all());

    expect($stateService->pendingClarification($thread))->toBeNull();
});

test('clarification answer enforces schedule selected-vs-whole-day', function (): void {
    config()->set('task-assistant.intent.merge.clarify_margin', 0.99);
    config()->set('task-assistant.intent.merge.clarify_composite_ceiling', 0.99);

    Prism::fake([
        // First: intent inference selects schedule with low confidence,
        // so it triggers clarification.
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'scheduling',
                'confidence' => 0.45,
                'rationale' => 'Ambiguous.',
            ])
            ->withUsage(new Usage(1, 2)),
        // Second: schedule narrative refinement
        StructuredResponseFake::make()
            ->withStructured([])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();

    $tasks = Task::factory()
        ->for($user)
        ->count(2)
        ->create([
            'status' => TaskStatus::ToDo,
            'priority' => TaskPriority::High,
            'start_datetime' => null,
            'end_datetime' => now()->addDay(),
            'duration' => 45,
        ]);

    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $stateService = app(TaskAssistantConversationStateService::class);

    $items = $tasks->map(static fn (Task $task): array => [
        'entity_type' => 'task',
        'entity_id' => $task->id,
        'title' => (string) $task->title,
    ])->values()->all();

    $stateService->rememberPrioritizedItems($thread, $items, 2);

    $userMessage1 = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'tasks',
    ]);
    $assistantMessage1 = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage1->id, $assistantMessage1->id);

    $assistantMessage1->refresh();
    $thread->refresh();

    expect($assistantMessage1->metadata['structured']['flow'] ?? null)->toBe('clarify');
    expect($stateService->pendingClarification($thread)['target_flow'] ?? null)->toBe('schedule');

    $userMessage2 = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'plan my whole day',
    ]);
    $assistantMessage2 = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage2->id, $assistantMessage2->id);

    $assistantMessage2->refresh();
    $thread->refresh();

    expect($assistantMessage2->metadata['structured']['flow'] ?? null)->toBe('schedule');

    $lastSchedule = data_get($thread->metadata, 'conversation_state.last_schedule');
    $targetEntities = data_get($lastSchedule, 'target_entities', []);
    expect(is_array($targetEntities))->toBeTrue();
    expect($targetEntities)->toEqual([]);

    expect($stateService->pendingClarification($thread))->toBeNull();
});
