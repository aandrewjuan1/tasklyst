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

test('pending general guidance forced schedule uses forced-flow constraints (not re-routing)', function (): void {
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

    $prioritizedItems = $tasks->map(static fn (Task $task): array => [
        'entity_type' => 'task',
        'entity_id' => $task->id,
        'title' => (string) $task->title,
    ])->values()->all();

    $stateService->rememberPrioritizedItems($thread, $prioritizedItems, 3);

    Prism::fake([
        // 1) resolveTargetFromAnswer chooses schedule
        StructuredResponseFake::make()
            ->withStructured([
                'target' => 'schedule',
                'confidence' => 0.9,
                'rationale' => 'User answered in a scheduling direction.',
            ])
            ->withUsage(new Usage(1, 2)),
        // 2) schedule narrative refinement
        StructuredResponseFake::make()
            ->withStructured([])
            ->withUsage(new Usage(5, 10)),
    ]);

    $stateService->rememberPendingGeneralGuidance(
        $thread,
        'hahawdakiodwak',
        'Do you want me to show your top tasks, or help plan time blocks for them?',
        ['general_guidance_heuristic']
    );

    $userMessage2 = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'those 2 tasks in the afternoon',
    ]);
    $assistantMessage2 = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage2->id, $assistantMessage2->id);

    $assistantMessage2->refresh();
    expect($assistantMessage2->metadata['structured']['flow'] ?? null)->toBe('schedule');

    $lastSchedule = data_get($thread->fresh()->metadata, 'conversation_state.last_schedule');
    $targetEntities = data_get($lastSchedule, 'target_entities', []);

    expect(is_array($targetEntities))->toBeTrue();
    expect($targetEntities)->toHaveCount(2);
    expect(array_column($targetEntities, 'entity_id'))->toEqual($tasks->pluck('id')->take(2)->values()->all());

    $stateService = app(TaskAssistantConversationStateService::class);
    expect($stateService->pendingGeneralGuidance($thread))->toBeNull();
});
