<?php

use App\Enums\EventStatus;
use App\Enums\MessageRole;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\DatabaseNotification;
use App\Models\Event;
use App\Models\SchoolClass;
use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Notifications\AssistantResponseReadyNotification;
use App\Services\LLM\TaskAssistant\TaskAssistantQuickChipResolver;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use App\Support\LLM\TaskAssistantReasonCodes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

test('queued prioritize flow stores selected entities for multiturn state', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'prioritization',
                'confidence' => 0.95,
                'rationale' => 'User asked for top tasks.',
            ])
            ->withUsage(new Usage(1, 2)),
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Start with the most urgent item first, then move down the list.',
                'acknowledgment' => null,
                'reasoning' => 'These tasks matched the filters and score highest by urgency.',
                'next_options' => 'If you want, I can schedule these steps for later.',
                'next_options_chip_texts' => ['Schedule these for later'],
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Task::factory()->for($user)->count(4)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'List my top 3 tasks',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $thread->refresh();
    $assistantMessage->refresh();

    $state = $thread->metadata['conversation_state'] ?? [];
    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('prioritize');
    expect($state['last_listing']['items'] ?? [])->toHaveCount(3);
    expect($state['last_listing']['source_flow'] ?? null)->toBe('prioritize');
});

test('multiturn schedule can target previous prioritized selection', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Task::factory()->for($user)->count(3)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
        'duration' => 45,
    ]);

    $service = app(TaskAssistantService::class);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'prioritization',
                'confidence' => 0.95,
                'rationale' => 'User asked for top tasks.',
            ])
            ->withUsage(new Usage(1, 2)),
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Start with the item that feels most doable today, then proceed down the ranked list.',
                'acknowledgment' => null,
                'reasoning' => 'These tasks matched the filters and score highest by urgency.',
                'next_options' => 'If you want, I can schedule these steps for later.',
                'next_options_chip_texts' => ['Schedule these for later'],
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $firstUser = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'List my top 3 tasks',
    ]);
    $firstAssistant = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);
    $service->processQueuedMessage($thread, $firstUser->id, $firstAssistant->id);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'scheduling',
                'confidence' => 0.95,
                'rationale' => 'User wants to schedule selected items.',
            ])
            ->withUsage(new Usage(1, 2)),
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Here is a plan for your afternoon window.',
                'reasoning' => 'This aligns with your requested window and the gaps on your calendar.',
                'confirmation' => 'Do these times work, or should we try a different part of the day?',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $secondUser = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Schedule those 3 for later afternoon',
    ]);
    $secondAssistant = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);
    $service->processQueuedMessage($thread, $secondUser->id, $secondAssistant->id);

    $firstAssistant->refresh();
    $firstAssistant->refresh();
    $firstAssistant->refresh();
    $secondAssistant->refresh();

    expect($secondAssistant->metadata['structured']['flow'] ?? null)->toBe('schedule');
    expect($secondAssistant->metadata['schedule']['proposals'] ?? null)->toBeArray();
});

test('fresh-thread schedule intent reroutes to prioritize when there is no listing context', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Task::factory()->for($user)->count(4)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
        'duration' => 45,
    ]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Here is a plan for your afternoon window.',
                'reasoning' => 'This aligns with your requested window and the gaps on your calendar.',
                'confirmation' => 'Do these times work, or should we try a different part of the day?',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Schedule top 1 for later afternoon',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('prioritize_schedule');
    expect($assistantMessage->metadata['schedule']['proposals'] ?? null)->toBeArray();
    expect((string) ($assistantMessage->content ?? ''))->not->toContain('pending_schedule:');
});

test('prioritize_schedule schedules tasks only (events present)', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Here is a plan for your schedule.',
                'reasoning' => 'I scheduled the highest priority tasks into your available time.',
                'confirmation' => 'Do these times work for you?',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $tomorrow = CarbonImmutable::now()->addDay();

    Task::factory()->for($user)->count(3)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'start_datetime' => null,
        'end_datetime' => $tomorrow,
        'duration' => 45,
    ]);

    // Block some time tomorrow so events are present in the workspace context.
    Event::factory()->for($user)->count(2)->create([
        'status' => EventStatus::Scheduled,
        'start_datetime' => $tomorrow->setTime(9, 0),
        'end_datetime' => $tomorrow->setTime(10, 0),
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'schedule my top tasks for tomorrow',
    ]);

    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    $proposals = $assistantMessage->metadata['schedule']['proposals'] ?? [];
    $selectionExplanation = $assistantMessage->metadata['schedule']['prioritize_selection_explanation'] ?? null;
    expect($proposals)->toBeArray();
    expect(count($proposals))->toBeGreaterThan(0);
    expect($selectionExplanation)->toBeArray();
    expect($selectionExplanation['enabled'] ?? null)->toBeTrue();
    expect($selectionExplanation['target_mode'] ?? null)->toBe('implicit_ranked');
    expect($selectionExplanation['selected_count'] ?? 0)->toBe(count($proposals));
    expect((string) ($assistantMessage->content ?? ''))->toContain('I picked these tasks first because they stood out most clearly in your current priorities before I placed them into time blocks.');
    expect((string) ($assistantMessage->content ?? ''))->not->toContain('Here are your prioritized items, placed into schedule blocks:');
    expect((string) ($assistantMessage->content ?? ''))->not->toContain('• #1 ');

    $entityTypes = array_values(array_unique(array_filter(array_map(
        static fn (mixed $p): string => is_array($p) ? (string) ($p['entity_type'] ?? '') : '',
        $proposals
    ), static fn (string $t): bool => $t !== '')));

    expect($entityTypes)->toEqual(['task']);
});

test('prioritize_schedule schedules the top student-first task selection', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Here is a focused schedule for your top task.',
                'reasoning' => 'This keeps your strongest study priority in your next open block.',
                'confirmation' => 'Do these times work for you?',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $timezone = (string) config('app.timezone', 'UTC');
    $tomorrow = CarbonImmutable::now($timezone)->addDay();

    $academic = Task::factory()->for($user)->create([
        'title' => 'Study calculus chapter 5',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
        'start_datetime' => null,
        'end_datetime' => $tomorrow->setTime(18, 0),
        'duration' => 50,
    ]);

    Task::factory()->for($user)->create([
        'title' => 'Clean desk drawer',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
        'start_datetime' => null,
        'end_datetime' => $tomorrow->setTime(18, 0),
        'duration' => 50,
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'schedule my top 1 task for tomorrow',
    ]);

    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    $proposals = $assistantMessage->metadata['schedule']['proposals'] ?? [];
    $selectionExplanation = $assistantMessage->metadata['schedule']['prioritize_selection_explanation'] ?? null;

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('prioritize_schedule');
    expect($proposals)->toBeArray();
    expect(count($proposals))->toBeGreaterThan(0);
    expect((string) ($proposals[0]['entity_type'] ?? ''))->toBe('task');
    expect((int) ($proposals[0]['entity_id'] ?? 0))->toBe($academic->id);
    expect($selectionExplanation)->toBeArray();
    expect($selectionExplanation['enabled'] ?? null)->toBeTrue();
    expect($selectionExplanation['selected_count'] ?? null)->toBe(1);
    expect(is_array($selectionExplanation['ordering_rationale'] ?? null))->toBeTrue();
    expect((string) ($assistantMessage->content ?? ''))->toContain('I picked this task first because it stood out most clearly in your current priorities before I placed it into a time block.');
    expect((string) ($assistantMessage->content ?? ''))->not->toContain('Here are your prioritized items, placed into schedule blocks:');
    expect((string) ($assistantMessage->content ?? ''))->not->toContain('• #1 ');
});

test('prioritize_schedule with only doing tasks does not schedule and returns doing guidance', function (): void {
    config([
        'task-assistant.intent.use_llm' => false,
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Task::factory()->for($user)->create([
        'title' => 'Implement linked list lab exercises',
        'status' => TaskStatus::Doing,
        'priority' => TaskPriority::High,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
        'duration' => 240,
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Schedule my top 1 for later',
    ]);

    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    $proposals = $assistantMessage->metadata['schedule']['proposals'] ?? [];
    $items = $assistantMessage->metadata['schedule']['items'] ?? [];

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('prioritize_schedule');
    expect($proposals)->toBeArray()->toHaveCount(0);
    expect($items)->toBeArray()->toHaveCount(0);
    expect((string) $assistantMessage->content)->toContain('I will not schedule tasks already marked as in progress');
    expect((string) $assistantMessage->content)->toContain('Implement linked list lab exercises');
});

test('edit-like turn binds refinement to latest schedule draft message', function (): void {
    config([
        'task-assistant.intent.use_llm' => false,
    ]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Updated draft order.',
                'reasoning' => 'I reordered based on your edit request.',
                'confirmation' => 'Tell me if you want another tweak.',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Older draft schedule',
        'metadata' => [
            'schedule' => [
                'proposals' => [
                    [
                        'proposal_id' => 'older-a',
                        'status' => 'pending',
                        'entity_type' => 'task',
                        'entity_id' => 1,
                        'title' => 'Old Task A',
                        'start_datetime' => '2026-04-02T08:00:00+08:00',
                        'end_datetime' => '2026-04-02T09:00:00+08:00',
                        'duration_minutes' => 60,
                        'apply_payload' => ['action' => 'update_task', 'arguments' => ['taskId' => 1, 'updates' => []]],
                    ],
                    [
                        'proposal_id' => 'older-b',
                        'status' => 'pending',
                        'entity_type' => 'task',
                        'entity_id' => 2,
                        'title' => 'Old Task B',
                        'start_datetime' => '2026-04-02T09:30:00+08:00',
                        'end_datetime' => '2026-04-02T10:00:00+08:00',
                        'duration_minutes' => 30,
                        'apply_payload' => ['action' => 'update_task', 'arguments' => ['taskId' => 2, 'updates' => []]],
                    ],
                ],
            ],
            'structured' => [
                'flow' => 'schedule',
            ],
        ],
    ]);

    $latestDraft = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Latest draft schedule',
        'metadata' => [
            'schedule' => [
                'proposals' => [
                    [
                        'proposal_id' => 'latest-a',
                        'status' => 'pending',
                        'entity_type' => 'task',
                        'entity_id' => 101,
                        'title' => 'Latest Task A',
                        'start_datetime' => '2026-04-03T08:00:00+08:00',
                        'end_datetime' => '2026-04-03T09:00:00+08:00',
                        'duration_minutes' => 60,
                        'apply_payload' => ['action' => 'update_task', 'arguments' => ['taskId' => 101, 'updates' => []]],
                    ],
                    [
                        'proposal_id' => 'latest-b',
                        'status' => 'pending',
                        'entity_type' => 'task',
                        'entity_id' => 102,
                        'title' => 'Latest Task B',
                        'start_datetime' => '2026-04-03T09:30:00+08:00',
                        'end_datetime' => '2026-04-03T10:00:00+08:00',
                        'duration_minutes' => 30,
                        'apply_payload' => ['action' => 'update_task', 'arguments' => ['taskId' => 102, 'updates' => []]],
                    ],
                ],
            ],
            'structured' => [
                'flow' => 'schedule',
            ],
        ],
    ]);

    expect($latestDraft->id)->toBeInt();

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'move the first one to last',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    expect(in_array((string) ($assistantMessage->metadata['structured']['flow'] ?? ''), ['schedule', 'prioritize_schedule'], true))->toBeTrue();
    expect($assistantMessage->metadata['schedule']['proposals'] ?? null)->toBeArray();
    expect($assistantMessage->metadata['schedule']['proposals'][0]['proposal_id'] ?? null)->toBe('latest-b');
    expect(array_column($assistantMessage->metadata['schedule']['proposals'] ?? [], 'proposal_id'))->not->toContain('older-a');
});

test('edit-like turn falls back to fresh schedule when latest draft is not refinable', function (): void {
    config([
        'task-assistant.intent.use_llm' => false,
    ]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Fresh schedule proposal.',
                'reasoning' => 'Placed your top tasks into available time slots.',
                'confirmation' => 'Do these times work?',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    // Older refinable draft exists.
    $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Older pending draft',
        'metadata' => [
            'schedule' => [
                'proposals' => [[
                    'proposal_id' => 'older-a',
                    'status' => 'pending',
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'Older Task A',
                    'start_datetime' => '2026-04-02T08:00:00+08:00',
                    'end_datetime' => '2026-04-02T09:00:00+08:00',
                    'duration_minutes' => 60,
                    'apply_payload' => ['action' => 'update_task', 'arguments' => ['taskId' => 1, 'updates' => []]],
                ]],
            ],
            'structured' => ['flow' => 'schedule'],
        ],
    ]);

    // Latest draft is non-refinable (no pending proposals).
    $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Latest non-refinable draft',
        'metadata' => [
            'schedule' => [
                'proposals' => [[
                    'proposal_id' => 'latest-applied',
                    'status' => 'applied',
                    'entity_type' => 'task',
                    'entity_id' => 99,
                    'title' => 'Applied task',
                    'start_datetime' => '2026-04-03T08:00:00+08:00',
                    'end_datetime' => '2026-04-03T09:00:00+08:00',
                    'duration_minutes' => 60,
                    'apply_payload' => ['action' => 'update_task', 'arguments' => ['taskId' => 99, 'updates' => []]],
                ]],
            ],
            'structured' => ['flow' => 'schedule'],
        ],
    ]);

    Task::factory()->for($user)->count(2)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'duration' => 60,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'move the first later today at evening',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    expect((string) ($assistantMessage->metadata['structured']['flow'] ?? ''))->toBe('schedule');
    expect(array_column($assistantMessage->metadata['schedule']['proposals'] ?? [], 'proposal_id'))->not->toContain('older-a');
});

test('schedule refinement without explicit day keeps edited item on its original scheduled date', function (): void {
    config([
        'task-assistant.intent.use_llm' => false,
    ]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-03 18:30:00', config('app.timezone', 'UTC')));

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Updated evening slot.',
                'reasoning' => 'Kept the same day and moved to evening.',
                'confirmation' => 'Tell me if you want another tweak.',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $taskA = Task::factory()->for($user)->create(['status' => TaskStatus::ToDo, 'duration' => 60]);
    $taskB = Task::factory()->for($user)->create(['status' => TaskStatus::ToDo, 'duration' => 60]);
    $taskC = Task::factory()->for($user)->create(['status' => TaskStatus::ToDo, 'duration' => 90]);

    $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Draft schedule',
        'metadata' => [
            'schedule' => [
                'proposals' => [
                    [
                        'proposal_id' => 'a',
                        'status' => 'pending',
                        'entity_type' => 'task',
                        'entity_id' => $taskA->id,
                        'title' => $taskA->title,
                        'start_datetime' => '2026-04-04T08:00:00+08:00',
                        'end_datetime' => '2026-04-04T09:00:00+08:00',
                        'duration_minutes' => 60,
                        'apply_payload' => ['action' => 'update_task', 'arguments' => ['taskId' => $taskA->id, 'updates' => []]],
                    ],
                    [
                        'proposal_id' => 'b',
                        'status' => 'pending',
                        'entity_type' => 'task',
                        'entity_id' => $taskB->id,
                        'title' => $taskB->title,
                        'start_datetime' => '2026-04-04T10:00:00+08:00',
                        'end_datetime' => '2026-04-04T11:00:00+08:00',
                        'duration_minutes' => 60,
                        'apply_payload' => ['action' => 'update_task', 'arguments' => ['taskId' => $taskB->id, 'updates' => []]],
                    ],
                    [
                        'proposal_id' => 'c',
                        'status' => 'pending',
                        'entity_type' => 'task',
                        'entity_id' => $taskC->id,
                        'title' => $taskC->title,
                        'start_datetime' => '2026-04-04T13:00:00+08:00',
                        'end_datetime' => '2026-04-04T14:30:00+08:00',
                        'duration_minutes' => 90,
                        'apply_payload' => ['action' => 'update_task', 'arguments' => ['taskId' => $taskC->id, 'updates' => []]],
                    ],
                ],
            ],
            'structured' => [
                'flow' => 'schedule',
            ],
        ],
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'move the third one at evening instead',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    $proposals = $assistantMessage->metadata['schedule']['proposals'] ?? [];
    expect($proposals)->toHaveCount(3);

    $third = $proposals[2] ?? [];
    expect((string) ($third['proposal_id'] ?? ''))->toBe('c');
    expect(str_starts_with((string) ($third['start_datetime'] ?? ''), '2026-04-04T'))->toBeTrue();

    CarbonImmutable::setTestNow();
});

test('schedule refinement later today overrides only edited item day to today', function (): void {
    config([
        'task-assistant.intent.use_llm' => false,
    ]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-03 18:30:00', config('app.timezone', 'UTC')));

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Updated first item for today.',
                'reasoning' => 'Applied your explicit today override for the first item.',
                'confirmation' => 'Tell me if you want another tweak.',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $taskA = Task::factory()->for($user)->create(['status' => TaskStatus::ToDo, 'duration' => 60]);
    $taskB = Task::factory()->for($user)->create(['status' => TaskStatus::ToDo, 'duration' => 60]);
    $taskC = Task::factory()->for($user)->create(['status' => TaskStatus::ToDo, 'duration' => 90]);

    $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Draft schedule',
        'metadata' => [
            'schedule' => [
                'proposals' => [
                    [
                        'proposal_id' => 'a',
                        'status' => 'pending',
                        'entity_type' => 'task',
                        'entity_id' => $taskA->id,
                        'title' => $taskA->title,
                        'start_datetime' => '2026-04-04T08:00:00+08:00',
                        'end_datetime' => '2026-04-04T09:00:00+08:00',
                        'duration_minutes' => 60,
                        'apply_payload' => ['action' => 'update_task', 'arguments' => ['taskId' => $taskA->id, 'updates' => []]],
                    ],
                    [
                        'proposal_id' => 'b',
                        'status' => 'pending',
                        'entity_type' => 'task',
                        'entity_id' => $taskB->id,
                        'title' => $taskB->title,
                        'start_datetime' => '2026-04-04T10:00:00+08:00',
                        'end_datetime' => '2026-04-04T11:00:00+08:00',
                        'duration_minutes' => 60,
                        'apply_payload' => ['action' => 'update_task', 'arguments' => ['taskId' => $taskB->id, 'updates' => []]],
                    ],
                    [
                        'proposal_id' => 'c',
                        'status' => 'pending',
                        'entity_type' => 'task',
                        'entity_id' => $taskC->id,
                        'title' => $taskC->title,
                        'start_datetime' => '2026-04-04T13:00:00+08:00',
                        'end_datetime' => '2026-04-04T14:30:00+08:00',
                        'duration_minutes' => 90,
                        'apply_payload' => ['action' => 'update_task', 'arguments' => ['taskId' => $taskC->id, 'updates' => []]],
                    ],
                ],
            ],
            'structured' => [
                'flow' => 'schedule',
            ],
        ],
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'i wanna do the first one later today',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    $proposals = $assistantMessage->metadata['schedule']['proposals'] ?? [];
    expect($proposals)->toHaveCount(3);

    $first = $proposals[0] ?? [];
    $second = $proposals[1] ?? [];
    $third = $proposals[2] ?? [];

    expect((string) ($first['proposal_id'] ?? ''))->toBe('a');
    expect(str_starts_with((string) ($first['start_datetime'] ?? ''), '2026-04-03T'))->toBeTrue();
    expect(str_starts_with((string) ($second['start_datetime'] ?? ''), '2026-04-04T'))->toBeTrue();
    expect(str_starts_with((string) ($third['start_datetime'] ?? ''), '2026-04-04T'))->toBeTrue();

    CarbonImmutable::setTestNow();
});

test('fresh prioritize ask still stays prioritize even when pending schedule draft exists', function (): void {
    config([
        'task-assistant.intent.use_llm' => false,
    ]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Start with the most urgent item first.',
                'acknowledgment' => null,
                'reasoning' => 'Ranked by urgency.',
                'next_options' => 'If you want, I can schedule these steps for later.',
                'next_options_chip_texts' => ['Schedule these for later'],
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    Task::factory()->for($user)->count(3)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
    ]);

    $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Draft schedule',
        'metadata' => [
            'schedule' => [
                'proposals' => [[
                    'proposal_id' => 'a',
                    'status' => 'pending',
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'Task A',
                    'start_datetime' => '2026-04-02T08:00:00+08:00',
                    'end_datetime' => '2026-04-02T09:00:00+08:00',
                    'duration_minutes' => 60,
                    'apply_payload' => ['action' => 'update_task', 'arguments' => ['taskId' => 1, 'updates' => []]],
                ]],
            ],
            'structured' => ['flow' => 'schedule'],
        ],
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'what are my top tasks now',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);
    $assistantMessage->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('prioritize');
});

test('prioritize_schedule stays fresh schedule when pending schedule draft exists (non-edit prompt)', function (): void {
    config([
        'task-assistant.intent.use_llm' => false,
    ]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Fresh schedule proposal.',
                'reasoning' => 'Placed your top tasks into available time slots.',
                'confirmation' => 'Do these times work?',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    // Seed a pending schedule draft so schedule refinement is possible.
    $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Draft schedule',
        'metadata' => [
            'schedule' => [
                'proposals' => [[
                    'proposal_id' => 'a',
                    'status' => 'pending',
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'Task A',
                    'start_datetime' => '2026-04-02T08:00:00+08:00',
                    'end_datetime' => '2026-04-02T09:00:00+08:00',
                    'duration_minutes' => 60,
                    'apply_payload' => ['action' => 'update_task', 'arguments' => ['taskId' => 1, 'updates' => []]],
                ]],
            ],
            'structured' => [
                'flow' => 'schedule',
            ],
        ],
    ]);

    Task::factory()->for($user)->count(3)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
        'duration' => 45,
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'schedule my top tasks for tomorrow',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('prioritize_schedule');
    expect($assistantMessage->metadata['schedule']['proposals'] ?? null)->toBeArray();
});

test('whole-day fresh planning prompt does not rewrite to schedule_refinement when draft exists', function (): void {
    config([
        'task-assistant.intent.use_llm' => false,
    ]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Fresh schedule proposal.',
                'reasoning' => 'Placed your top tasks into available time slots.',
                'confirmation' => 'Do these times work?',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Draft schedule',
        'metadata' => [
            'schedule' => [
                'proposals' => [[
                    'proposal_id' => 'a',
                    'status' => 'pending',
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'Task A',
                    'start_datetime' => '2026-04-02T08:00:00+08:00',
                    'end_datetime' => '2026-04-02T09:00:00+08:00',
                    'duration_minutes' => 60,
                    'apply_payload' => ['action' => 'update_task', 'arguments' => ['taskId' => 1, 'updates' => []]],
                ]],
            ],
            'structured' => [
                'flow' => 'schedule',
            ],
        ],
    ]);

    Task::factory()->for($user)->count(3)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
        'duration' => 45,
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'i said plan my whole day later schedule all important tasks that i need to do asap',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('prioritize_schedule');
    expect($assistantMessage->metadata['structured']['flow'] ?? null)->not->toBe('schedule_refinement');
});

test('processQueuedMessage clears task assistant container bindings after run', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    Prism::fake([
        TextResponseFake::make()
            ->withText('Hello.')
            ->withFinishReason(FinishReason::Stop)
            ->withToolCalls([])
            ->withToolResults([])
            ->withUsage(new Usage(1, 2))
            ->withMeta(new Meta('fake', 'fake')),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'hello',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    expect(app()->bound('task_assistant.thread_id'))->toBeFalse();
    expect(app()->bound('task_assistant.message_id'))->toBeFalse();
});

test('greeting is routed to general_guidance and persists structured metadata', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'task',
                'acknowledgement' => 'Hello.',
                'message' => 'I can help you organize tasks and time.',
                'suggested_next_actions' => [
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
        'content' => 'hello',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    Log::spy();

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('general_guidance');
    expect(data_get($assistantMessage->metadata, 'routing_trace.initial_flow'))->toBe('general_guidance');
    expect(data_get($assistantMessage->metadata, 'routing_trace.final_flow'))->toBe('general_guidance');
    expect(data_get($assistantMessage->metadata, 'routing_trace.rewrites'))->toBeArray();
    expect(trim((string) $assistantMessage->content))->not->toBe('');

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($thread, $assistantMessage): bool {
        return $message === 'task-assistant.routing_decision'
            && ($context['thread_id'] ?? null) === $thread->id
            && ($context['assistant_message_id'] ?? null) === $assistantMessage->id
            && is_string($context['run_id'] ?? null)
            && ($context['flow'] ?? null) === 'general_guidance';
    })->atLeast()->once();
});

test('greeting-only prompt uses deterministic greeting guidance with three main-flow chips', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'hi bro',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    $chips = data_get($assistantMessage->metadata, 'general_guidance.next_options_chip_texts', []);
    $expectedDynamic = app(TaskAssistantQuickChipResolver::class)
        ->filterContinueStyleQuickChips(
            app(TaskAssistantQuickChipResolver::class)->resolveForEmptyState(
                user: $user,
                thread: $thread,
                limit: 4
            )
        );
    $expectedDynamic = array_values(array_slice($expectedDynamic, 0, 3));

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('general_guidance');
    expect(data_get($assistantMessage->metadata, 'general_guidance.subtype'))->toBe('greeting');
    expect(data_get($assistantMessage->metadata, 'routing_trace.final_reason_codes', []))->toContain(
        TaskAssistantReasonCodes::GENERAL_GUIDANCE_GREETING_ONLY_DETERMINISTIC
    );
    expect($chips)->toBeArray();
    expect($chips)->toHaveCount(3);
    expect($chips)->toBe($expectedDynamic);
});

test('greeting-only polite opener with name mention uses deterministic greeting guidance', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'good morning tasklyst',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    expect(data_get($assistantMessage->metadata, 'general_guidance.subtype'))->toBe('greeting');
});

test('greeting-only hello there uses deterministic greeting guidance', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'hello there',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    expect(data_get($assistantMessage->metadata, 'general_guidance.subtype'))->toBe('greeting');
    expect(data_get($assistantMessage->metadata, 'routing_trace.final_reason_codes', []))->toContain(
        TaskAssistantReasonCodes::GENERAL_GUIDANCE_GREETING_ONLY_DETERMINISTIC
    );
});

test('mixed greeting plus actionable intent does not use deterministic greeting-only flow', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Start with the most urgent task first.',
                'acknowledgment' => null,
                'reasoning' => 'These tasks matched the highest urgency.',
                'next_options' => 'If you want, I can schedule these next.',
                'next_options_chip_texts' => ['Schedule these next'],
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    Task::factory()->for($user)->count(3)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'hello schedule my tasks',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    expect(data_get($assistantMessage->metadata, 'general_guidance.subtype'))->not->toBe('greeting');
    expect(data_get($assistantMessage->metadata, 'routing_trace.final_reason_codes', []))->not->toContain(
        TaskAssistantReasonCodes::GENERAL_GUIDANCE_GREETING_ONLY_DETERMINISTIC
    );
});

test('closing thanks message is short-circuited to deterministic general_guidance reply', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'okay thank you so much',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('general_guidance');
    expect(data_get($assistantMessage->metadata, 'routing_trace.final_flow'))->toBe('general_guidance');
    expect(data_get($assistantMessage->metadata, 'routing_trace.final_reason_codes', []))->toContain(
        TaskAssistantReasonCodes::GENERAL_GUIDANCE_CLOSING_ONLY
    );
    expect(mb_strtolower((string) $assistantMessage->content))->toContain('you are welcome');
    expect(data_get($assistantMessage->metadata, 'general_guidance.subtype'))->toBe('closing');
});

test('closing goodbye combo is short-circuited to deterministic closing guidance', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'ok thanks bye',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    expect(data_get($assistantMessage->metadata, 'general_guidance.subtype'))->toBe('closing');
    expect(data_get($assistantMessage->metadata, 'routing_trace.final_reason_codes', []))->toContain(
        TaskAssistantReasonCodes::GENERAL_GUIDANCE_CLOSING_ONLY
    );
});

test('short acknowledgement after planning context is routed as deterministic closing guidance', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Start with the most urgent task first.',
                'acknowledgment' => null,
                'reasoning' => 'These tasks are due soonest.',
                'next_options' => 'If you want, I can schedule these tasks for today.',
                'next_options_chip_texts' => ['Schedule these tasks'],
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    Task::factory()->for($user)->count(3)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
    ]);

    $firstUser = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'list my top 3 tasks',
    ]);
    $firstAssistant = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);
    app(TaskAssistantService::class)->processQueuedMessage($thread, $firstUser->id, $firstAssistant->id);

    $secondUser = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'ok',
    ]);
    $secondAssistant = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);
    app(TaskAssistantService::class)->processQueuedMessage($thread, $secondUser->id, $secondAssistant->id);

    $secondAssistant->refresh();
    expect($secondAssistant->metadata['structured']['flow'] ?? null)->toBe('general_guidance');
    expect(data_get($secondAssistant->metadata, 'routing_trace.final_reason_codes', []))->toContain(
        TaskAssistantReasonCodes::GENERAL_GUIDANCE_CLOSING_ONLY
    );
});

test('closing-like prompt with actionable edit cue stays in scheduling flow', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Here is your adjusted schedule.',
                'reasoning' => 'I moved the block to match your requested time.',
                'confirmation' => 'Does this revised time work for you?',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    Task::factory()->for($user)->count(3)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
        'duration' => 45,
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'thanks, move it to 6pm',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    expect(data_get($assistantMessage->metadata, 'routing_trace.final_reason_codes', []))->not->toContain(
        TaskAssistantReasonCodes::GENERAL_GUIDANCE_CLOSING_ONLY
    );
});

test('prioritize flow replaces last_listing with prioritize results for multiturn state', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Start with the most urgent item first, then work down the list to keep your momentum.',
                'acknowledgment' => null,
                'reasoning' => 'These tasks matched the filters and score highest by urgency.',
                'next_options' => 'If you want, I can schedule these steps for later.',
                'next_options_chip_texts' => ['Schedule these for later'],
            ])
            ->withUsage(new Usage(5, 10)),
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Pick the first item to open so you can focus, then move to the next one if you still have time.',
                'acknowledgment' => null,
                'reasoning' => 'Ranked by urgency.',
                'next_options' => 'If you want, I can schedule these steps for later.',
                'next_options_chip_texts' => ['Schedule these for later'],
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Task::factory()->for($user)->count(3)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
    ]);

    $prioritizeUser = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Give me my top 3 tasks',
    ]);
    $prioritizeAssistant = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $prioritizeUser->id, $prioritizeAssistant->id);

    $thread->refresh();
    expect($thread->metadata['conversation_state']['last_listing']['source_flow'] ?? null)->toBe('prioritize');

    $browseUser = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'list my tasks',
    ]);
    $browseAssistant = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $browseUser->id, $browseAssistant->id);

    $thread->refresh();
    expect($thread->metadata['conversation_state']['last_listing']['source_flow'] ?? null)->toBe('prioritize');
    expect($thread->metadata['conversation_state']['last_listing']['items'] ?? [])->not->toBeEmpty();
});

test('empty workspace short-circuits prioritize with deterministic valid envelope', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'what should i do first',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['validation_errors'] ?? [])->toBe([]);
    expect($assistantMessage->metadata['structured']['ok'] ?? null)->toBeTrue();
    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('prioritize');
    expect($assistantMessage->metadata['prioritize']['workspace_empty'] ?? null)->toBeTrue();
    expect($assistantMessage->metadata['prioritize']['next_options_chip_texts'] ?? null)->toBe([]);
    expect($assistantMessage->metadata['processed'] ?? null)->toBeTrue();
    expect(trim((string) $assistantMessage->content))->not->toContain('Nothing matched that request yet');
    $framing = (string) config('task-assistant.listing.empty_workspace.framing', '');
    expect($framing)->not->toBe('');
    expect((string) $assistantMessage->content)->toContain(mb_substr($framing, 0, 40));
});

test('empty workspace short-circuits schedule intent with prioritize-shaped envelope', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'scheduling',
                'confidence' => 0.95,
                'rationale' => 'User asked to schedule.',
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Schedule time for my tasks this afternoon',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['structured']['ok'] ?? null)->toBeTrue();
    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('prioritize');
    expect($assistantMessage->metadata['prioritize']['workspace_empty'] ?? null)->toBeTrue();
    expect($assistantMessage->metadata['prioritize']['next_options_chip_texts'] ?? null)->toBe([]);
    expect($assistantMessage->metadata['prioritize']['workspace_empty_intended_flow'] ?? null)->toBe('schedule');
});

test('today schedule does not place new blocks before now', function (): void {
    config(['task-assistant.intent.use_llm' => false]);
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-03 17:40:00', $timezone));

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Task::factory()->for($user)->count(3)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
        'duration' => 60,
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'plan my top tasks',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    $proposals = $assistantMessage->metadata['schedule']['proposals'] ?? [];
    expect($proposals)->toBeArray();

    $now = CarbonImmutable::now($timezone);
    foreach ($proposals as $proposal) {
        $start = isset($proposal['start_datetime'])
            ? CarbonImmutable::parse((string) $proposal['start_datetime'], $timezone)
            : null;

        if ($start !== null && $start->toDateString() === $now->toDateString()) {
            expect($start->greaterThanOrEqualTo($now))->toBeTrue();
        }
    }

    CarbonImmutable::setTestNow();
});

test('schedule my most important task defaults to same-day placement when a slot is available', function (): void {
    config(['task-assistant.intent.use_llm' => false]);
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-12 16:00:00', $timezone));

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'I scheduled your most important task.',
                'reasoning' => 'I used the earliest open slot.',
                'confirmation' => 'Want this adjusted?',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    Task::factory()->for($user)->create([
        'title' => 'Most important task',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Urgent,
        'start_datetime' => null,
        'end_datetime' => CarbonImmutable::parse('2026-04-14 10:00:00', $timezone),
        'duration' => 60,
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Schedule my most important task',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);
    $assistantMessage->refresh();

    $proposal = $assistantMessage->metadata['schedule']['proposals'][0] ?? [];
    expect((string) ($assistantMessage->metadata['structured']['flow'] ?? ''))->toBe('prioritize_schedule');
    expect(str_starts_with((string) ($proposal['start_datetime'] ?? ''), '2026-04-12T'))->toBeTrue();

    CarbonImmutable::setTestNow();
});

test('default scheduling without time window applies prep buffer before same-day proposal start', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-25 17:03:00', $timezone));

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'I scheduled your run in the next feasible slot.',
                'reasoning' => 'I used the earliest realistic opening.',
                'confirmation' => 'Want this adjusted?',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Task::factory()->for($user)->create([
        'title' => '10KM RUN',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'start_datetime' => null,
        'end_datetime' => CarbonImmutable::parse('2026-04-27 10:00:00', $timezone),
        'duration' => 60,
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'schedule my 10km run',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);
    $assistantMessage->refresh();

    $proposal = $assistantMessage->metadata['schedule']['proposals'][0] ?? [];
    $digest = is_array($assistantMessage->metadata['schedule']['placement_digest'] ?? null)
        ? $assistantMessage->metadata['schedule']['placement_digest']
        : [];
    expect($proposal)->toBeArray()->not->toBeEmpty();
    expect((bool) ($digest['default_asap_mode'] ?? false))->toBeTrue();

    $startRaw = (string) ($proposal['start_datetime'] ?? '');
    expect($startRaw)->not->toBe('');

    $start = CarbonImmutable::parse($startRaw, $timezone);
    $expectedMinStart = CarbonImmutable::parse('2026-04-25 17:30:00', $timezone);
    expect($start->greaterThanOrEqualTo($expectedMinStart))->toBeTrue();
    expect(((int) $start->format('i')) % 15)->toBe(0);

    CarbonImmutable::setTestNow();
});

test('default scheduling does not mention blockers from non-proposal days in explanation', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-25 17:03:00', $timezone));

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'I scheduled your run in the next feasible slot.',
                'reasoning' => 'I used the earliest realistic opening.',
                'confirmation' => 'Want this adjusted?',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Task::factory()->for($user)->create([
        'title' => '10KM RUN',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'start_datetime' => null,
        'end_datetime' => CarbonImmutable::parse('2026-04-27 10:00:00', $timezone),
        'duration' => 60,
    ]);

    SchoolClass::factory()->for($user)->create([
        'subject_name' => 'ELECTIVE 3',
        'start_datetime' => CarbonImmutable::parse('2026-04-26 13:45:00', $timezone),
        'end_datetime' => CarbonImmutable::parse('2026-04-26 18:15:00', $timezone),
        'start_time' => '13:45:00',
        'end_time' => '18:15:00',
    ]);
    SchoolClass::factory()->for($user)->create([
        'subject_name' => 'YES',
        'start_datetime' => CarbonImmutable::parse('2026-04-26 12:45:00', $timezone),
        'end_datetime' => CarbonImmutable::parse('2026-04-26 14:15:00', $timezone),
        'start_time' => '12:45:00',
        'end_time' => '14:15:00',
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'schedule my 10km run',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);
    $assistantMessage->refresh();

    $proposal = is_array($assistantMessage->metadata['schedule']['proposals'][0] ?? null)
        ? $assistantMessage->metadata['schedule']['proposals'][0]
        : [];
    expect($proposal)->not->toBe([]);
    $start = CarbonImmutable::parse((string) ($proposal['start_datetime'] ?? ''), $timezone);
    expect($start->toDateString())->toBe('2026-04-25');

    $content = (string) ($assistantMessage->content ?? '');
    expect($content)->not->toContain('ELECTIVE 3');
    expect($content)->not->toContain('YES');

    CarbonImmutable::setTestNow();
});

test('schedule my most important task falls back to tomorrow when today is fully blocked', function (): void {
    config(['task-assistant.intent.use_llm' => false]);
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-12 16:00:00', $timezone));

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'I scheduled your most important task.',
                'reasoning' => 'Today is full, so I used the next open slot.',
                'confirmation' => 'Want this adjusted?',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    Task::factory()->for($user)->create([
        'title' => 'Most important task',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Urgent,
        'start_datetime' => null,
        'end_datetime' => CarbonImmutable::parse('2026-04-14 10:00:00', $timezone),
        'duration' => 60,
    ]);

    Event::factory()->for($user)->create([
        'status' => EventStatus::Scheduled,
        'start_datetime' => CarbonImmutable::parse('2026-04-12 08:00:00', $timezone),
        'end_datetime' => CarbonImmutable::parse('2026-04-12 22:00:00', $timezone),
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Schedule my most important task',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);
    $assistantMessage->refresh();

    $proposal = $assistantMessage->metadata['schedule']['proposals'][0] ?? [];
    expect((string) ($assistantMessage->metadata['structured']['flow'] ?? ''))->toBe('prioritize_schedule');
    expect(str_starts_with((string) ($proposal['start_datetime'] ?? ''), '2026-04-13T'))->toBeTrue();

    CarbonImmutable::setTestNow();
});

test('schedule proposals do not overlap buffered school class windows', function (): void {
    config([
        'task-assistant.intent.use_llm' => false,
        'task-assistant.schedule.school_class_buffer_minutes' => 15,
    ]);

    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-04 07:30:00', $timezone));

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $task = Task::factory()->for($user)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'duration' => 60,
        'start_datetime' => null,
        'end_datetime' => CarbonImmutable::parse('2026-05-05 21:00:00', $timezone),
    ]);

    expect($task->id)->toBeInt();

    SchoolClass::factory()->for($user)->create([
        'subject_name' => 'Calculus',
        'start_datetime' => CarbonImmutable::parse('2026-05-05 10:00:00', $timezone),
        'end_datetime' => CarbonImmutable::parse('2026-05-05 11:00:00', $timezone),
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'schedule my top task for tomorrow morning',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    $proposals = $assistantMessage->metadata['schedule']['proposals'] ?? [];
    expect($proposals)->toBeArray();
    expect($proposals)->not->toBeEmpty();

    $blockedStart = CarbonImmutable::parse('2026-05-05 09:45:00', $timezone);
    $blockedEnd = CarbonImmutable::parse('2026-05-05 11:15:00', $timezone);

    foreach ($proposals as $proposal) {
        if (! is_array($proposal)) {
            continue;
        }

        $startRaw = (string) ($proposal['start_datetime'] ?? '');
        $endRaw = (string) ($proposal['end_datetime'] ?? '');
        if ($startRaw === '' || $endRaw === '') {
            continue;
        }

        $start = CarbonImmutable::parse($startRaw, $timezone);
        $end = CarbonImmutable::parse($endRaw, $timezone);
        if ($end->lessThanOrEqualTo($start)) {
            continue;
        }

        $overlapsBufferedClass = $start->lessThan($blockedEnd) && $end->greaterThan($blockedStart);
        expect($overlapsBufferedClass)->toBeFalse();
    }

    CarbonImmutable::setTestNow();
});

test('non-ideal later scheduling emits confirmation-required fallback payload', function (): void {
    config(['task-assistant.intent.use_llm' => false]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-02 21:58:00', config('app.timezone', 'UTC')));

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'I prepared a schedule.',
                'reasoning' => 'Using the available window.',
                'confirmation' => 'Does this work?',
            ])
            ->withUsage(new Usage(5, 10)),
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'I drafted a backup plan and paused for your confirmation.',
                'reasoning' => 'There is not enough room in your current later-today window for everything.',
                'confirmation' => 'Do you want to keep this draft or pick another time window?',
                'reason_message' => 'There is not enough free time left in your requested window.',
            ])
            ->withUsage(new Usage(3, 8)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $task = Task::factory()->for($user)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'duration' => 60,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
    ]);

    $thread->update([
        'metadata' => [
            'conversation_state' => [
                'last_listing' => [
                    'source_flow' => 'prioritize',
                    'items' => [[
                        'entity_type' => 'task',
                        'entity_id' => $task->id,
                        'title' => $task->title,
                        'position' => 0,
                    ]],
                ],
            ],
        ],
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'schedule them later',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    $thread->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('schedule');
    expect($assistantMessage->metadata['schedule']['confirmation_required'] ?? null)->toBeTrue();
    expect($assistantMessage->metadata['schedule']['awaiting_user_decision'] ?? null)->toBeTrue();
    expect($assistantMessage->metadata['schedule']['window_selection_explanation'] ?? null)->toBeString();
    expect($assistantMessage->metadata['schedule']['ordering_rationale'] ?? null)->toBeArray();
    expect($assistantMessage->metadata['schedule']['blocking_reasons'] ?? null)->toBeArray();
    expect($thread->metadata['conversation_state']['pending_schedule_fallback'] ?? null)->toBeArray();
    CarbonImmutable::setTestNow();
});

test('robotic fallback narrative is rejected in favor of deterministic student-friendly confirmation copy', function (): void {
    config(['task-assistant.intent.use_llm' => false]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-02 21:58:00', config('app.timezone', 'UTC')));

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'I prepared a schedule.',
                'reasoning' => 'Using the available window.',
                'confirmation' => 'Does this work?',
            ])
            ->withUsage(new Usage(5, 10)),
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'I understand that I could not find a suitable time slot.',
                'reasoning' => 'Confidence: 0 of 1 open time slots were available for the horizon dates.',
                'confirmation' => 'The request was made explicitly by the user.',
                'reason_message' => 'Confidence: 0 of 1 open time slots were available.',
            ])
            ->withUsage(new Usage(3, 8)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $task = Task::factory()->for($user)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'duration' => 60,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
    ]);

    $thread->update([
        'metadata' => [
            'conversation_state' => [
                'last_listing' => [
                    'source_flow' => 'prioritize',
                    'items' => [[
                        'entity_type' => 'task',
                        'entity_id' => $task->id,
                        'title' => $task->title,
                        'position' => 0,
                    ]],
                ],
            ],
        ],
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'schedule top 1 for later',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['schedule']['confirmation_required'] ?? null)->toBeTrue();
    expect($assistantMessage->metadata['schedule']['confirmation_context']['reason_details'] ?? [])->not->toBe([]);
    $options = $assistantMessage->metadata['schedule']['confirmation_context']['options'] ?? [];
    expect($options)->toContain('Try another time window');
    expect($options)->not->toBe([]);
    expect($options)->not->toContain('Split it into shorter blocks today');
    expect((string) $assistantMessage->content)->not->toContain('Confidence:');
    expect((string) $assistantMessage->content)->not->toContain('explicitly by the user');
    expect((string) $assistantMessage->content)->toContain('What got in the way:');
    expect((string) $assistantMessage->content)->toContain('I prepared a draft and paused so you can review it before I finalize anything.');

    CarbonImmutable::setTestNow();
});

test('top N scheduling shortfall requires confirmation instead of silent underfilled finalization', function (): void {
    config([
        'task-assistant.intent.use_llm' => false,
        'task-assistant.schedule.top_n_shortfall_policy' => 'confirm_if_shortfall',
        'task-assistant.schedule.overflow_strategy' => 'require_confirm',
        'task-assistant.schedule.partial_policy' => 'top1_only',
    ]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Draft schedule prepared.',
                'reasoning' => 'I used your requested window.',
                'confirmation' => 'Would you like to continue?',
            ])
            ->withUsage(new Usage(5, 10)),
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'I prepared a draft and paused so you can choose what to do next.',
                'reasoning' => 'One task fit in the current window, and the rest need a wider window.',
                'confirmation' => 'Should I keep this draft or adjust your window to fit more?',
                'reason_message' => 'Only one task fit in your current window.',
            ])
            ->withUsage(new Usage(3, 8)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-17 14:00:00', $timezone));

    $top = Task::factory()->for($user)->create([
        'title' => 'Top long task',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Urgent,
        'duration' => 300,
        'start_datetime' => null,
        'end_datetime' => CarbonImmutable::parse('2026-04-18 09:00:00', $timezone),
    ]);
    $second = Task::factory()->for($user)->create([
        'title' => 'Second task',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'duration' => 75,
        'start_datetime' => null,
        'end_datetime' => CarbonImmutable::parse('2026-04-18 10:00:00', $timezone),
    ]);
    $third = Task::factory()->for($user)->create([
        'title' => 'Third task',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
        'duration' => 20,
        'start_datetime' => null,
        'end_datetime' => CarbonImmutable::parse('2026-04-18 11:00:00', $timezone),
    ]);

    $thread->update([
        'metadata' => [
            'conversation_state' => [
                'last_listing' => [
                    'source_flow' => 'prioritize',
                    'items' => [
                        ['entity_type' => 'task', 'entity_id' => $top->id, 'title' => $top->title, 'position' => 0],
                        ['entity_type' => 'task', 'entity_id' => $second->id, 'title' => $second->title, 'position' => 1],
                        ['entity_type' => 'task', 'entity_id' => $third->id, 'title' => $third->title, 'position' => 2],
                    ],
                ],
            ],
        ],
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'schedule top 3 for tomorrow afternoon',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    $thread->refresh();

    expect($assistantMessage->metadata['schedule']['confirmation_required'] ?? null)->toBeTrue();
    expect($assistantMessage->metadata['schedule']['awaiting_user_decision'] ?? null)->toBeTrue();
    expect($assistantMessage->metadata['schedule']['confirmation_context']['reason_code'] ?? null)->toBe('top_n_shortfall');
    expect($assistantMessage->metadata['schedule']['confirmation_context']['options'] ?? [])
        ->toContain('Continue with that plan')
        ->toContain('Try another time window')
        ->not->toContain('Cancel scheduling for now');
    expect($assistantMessage->metadata['schedule']['placement_digest']['top_n_shortfall'] ?? null)->toBeTrue();
    expect((string) ($assistantMessage->metadata['schedule']['framing'] ?? ''))->not->toContain('pending_schedule:');
    expect($thread->metadata['conversation_state']['pending_schedule_fallback'] ?? null)->toBeArray();

    CarbonImmutable::setTestNow();
});

test('multi-turn all-of-them scheduling follow-up is treated as strict contract', function (): void {
    config([
        'task-assistant.intent.use_llm' => false,
        'task-assistant.schedule.top_n_shortfall_policy' => 'confirm_if_shortfall',
        'task-assistant.schedule.overflow_strategy' => 'require_confirm',
        'task-assistant.schedule.partial_policy' => 'top1_only',
    ]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Here are your top priorities.',
                'acknowledgment' => null,
                'reasoning' => 'Ranked by urgency and due date.',
                'next_options' => 'I can schedule these for later if you want.',
                'next_options_chip_texts' => ['Schedule these for later'],
            ])
            ->withUsage(new Usage(5, 10)),
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'I preserved your requested set and paused for confirmation.',
                'reasoning' => 'Only part of the strict set fits this window.',
                'confirmation' => 'Choose whether to keep this draft or try another window.',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-23 17:55:00', $timezone));

    Task::factory()->for($user)->create([
        'title' => 'Task One',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Urgent,
        'duration' => 60,
        'start_datetime' => null,
        'end_datetime' => CarbonImmutable::parse('2026-04-24 09:00:00', $timezone),
    ]);
    Task::factory()->for($user)->create([
        'title' => 'Task Two',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'duration' => 60,
        'start_datetime' => null,
        'end_datetime' => CarbonImmutable::parse('2026-04-24 10:00:00', $timezone),
    ]);
    Task::factory()->for($user)->create([
        'title' => 'Task Three',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
        'duration' => 60,
        'start_datetime' => null,
        'end_datetime' => CarbonImmutable::parse('2026-04-24 11:00:00', $timezone),
    ]);
    Event::factory()->for($user)->create([
        'title' => 'Busy block',
        'start_datetime' => CarbonImmutable::parse('2026-04-23 19:30:00', $timezone),
        'end_datetime' => CarbonImmutable::parse('2026-04-23 20:30:00', $timezone),
    ]);

    $firstUser = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'what are my top 3 tasks',
    ]);
    $firstAssistant = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);
    app(TaskAssistantService::class)->processQueuedMessage($thread, $firstUser->id, $firstAssistant->id);

    $secondUser = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'schedule all of them for later',
    ]);
    $secondAssistant = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);
    app(TaskAssistantService::class)->processQueuedMessage($thread, $secondUser->id, $secondAssistant->id);

    $secondAssistant->refresh();
    $digest = is_array($secondAssistant->metadata['schedule']['placement_digest'] ?? null)
        ? $secondAssistant->metadata['schedule']['placement_digest']
        : [];
    $options = $secondAssistant->metadata['schedule']['confirmation_context']['options'] ?? [];

    expect($secondAssistant->metadata['structured']['flow'] ?? null)->toBe('schedule');
    expect($secondAssistant->metadata['schedule']['confirmation_required'] ?? null)->toBeTrue();
    expect($secondAssistant->metadata['schedule']['confirmation_context']['reason_code'] ?? null)->toBe('top_n_shortfall');
    expect($digest['requested_count_source'] ?? null)->toBe('explicit_user');
    expect($digest['is_strict_set_contract'] ?? null)->toBeTrue();
    expect($options)->toContain('Continue with that plan')->toContain('Try another time window');

    CarbonImmutable::setTestNow();
});

test('implicit top tasks later shortfall auto-proposes what fits without confirmation', function (): void {
    config([
        'task-assistant.intent.use_llm' => false,
        'task-assistant.schedule.top_n_shortfall_policy' => 'confirm_if_shortfall',
        'task-assistant.schedule.overflow_strategy' => 'require_confirm',
        'task-assistant.schedule.partial_policy' => 'top1_only',
    ]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'I queued what fit for later today.',
                'reasoning' => 'I prioritized realistic windows in your later-day range.',
                'confirmation' => 'If you want, I can add more time tomorrow.',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-23 17:55:00', $timezone));

    Task::factory()->for($user)->create([
        'title' => 'Task One',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Urgent,
        'duration' => 60,
        'start_datetime' => null,
        'end_datetime' => CarbonImmutable::parse('2026-04-24 09:00:00', $timezone),
    ]);
    Task::factory()->for($user)->create([
        'title' => 'Task Two',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'duration' => 60,
        'start_datetime' => null,
        'end_datetime' => CarbonImmutable::parse('2026-04-24 10:00:00', $timezone),
    ]);
    Task::factory()->for($user)->create([
        'title' => 'Task Three',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
        'duration' => 60,
        'start_datetime' => null,
        'end_datetime' => CarbonImmutable::parse('2026-04-24 11:00:00', $timezone),
    ]);
    Event::factory()->for($user)->create([
        'title' => 'Busy block',
        'start_datetime' => CarbonImmutable::parse('2026-04-23 19:30:00', $timezone),
        'end_datetime' => CarbonImmutable::parse('2026-04-23 20:30:00', $timezone),
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Schedule my top tasks later',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    $thread->refresh();

    $digest = is_array($assistantMessage->metadata['schedule']['placement_digest'] ?? null)
        ? $assistantMessage->metadata['schedule']['placement_digest']
        : [];

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('prioritize_schedule');
    expect($assistantMessage->metadata['schedule']['confirmation_required'] ?? null)->toBeFalse();
    expect($assistantMessage->metadata['schedule']['awaiting_user_decision'] ?? null)->toBeFalse();
    expect(count($assistantMessage->metadata['schedule']['proposals'] ?? []))->toBeGreaterThan(0);
    expect($digest['requested_count_source'] ?? null)->toBe('system_default');
    expect($digest['unplaced_units'] ?? [])->not->toBe([]);
    expect($thread->metadata['conversation_state']['pending_schedule_fallback'] ?? null)->toBeNull();

    CarbonImmutable::setTestNow();
});

test('system default schedule count does not require confirmation when all available tasks are placed', function (): void {
    config([
        'task-assistant.intent.use_llm' => false,
        'task-assistant.schedule.top_n_shortfall_policy' => 'confirm_if_shortfall',
        'task-assistant.schedule.overflow_strategy' => 'require_confirm',
    ]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'I prepared your schedule for later this week.',
                'reasoning' => 'I placed each available task into open time blocks.',
                'confirmation' => 'Do these times work for you?',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-19 00:45:00', $timezone));

    Task::factory()->for($user)->create([
        'title' => 'Assignment A',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'duration' => 60,
        'start_datetime' => null,
        'end_datetime' => CarbonImmutable::parse('2026-04-22 09:00:00', $timezone),
    ]);
    Task::factory()->for($user)->create([
        'title' => 'Exam review',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
        'duration' => 60,
        'start_datetime' => null,
        'end_datetime' => CarbonImmutable::parse('2026-04-24 12:00:00', $timezone),
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'schedule it later this week',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    $thread->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('prioritize_schedule');
    expect($assistantMessage->metadata['schedule']['confirmation_required'] ?? null)->toBeFalse();
    expect($assistantMessage->metadata['schedule']['awaiting_user_decision'] ?? null)->toBeFalse();
    expect($assistantMessage->metadata['schedule']['placement_digest']['top_n_shortfall'] ?? null)->toBeFalse();
    expect($assistantMessage->metadata['schedule']['placement_digest']['requested_count_source'] ?? null)->toBe('system_default');
    expect($thread->metadata['conversation_state']['pending_schedule_fallback'] ?? null)->toBeNull();

    CarbonImmutable::setTestNow();
});

test('generic top tasks schedule auto-spills to next days and does not require confirmation', function (): void {
    config([
        'task-assistant.intent.use_llm' => false,
        'task-assistant.schedule.smart_default_spread_days' => 7,
    ]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'I scheduled your top tasks in the nearest available blocks.',
                'reasoning' => 'Today has no remaining window, so I used the next open day.',
                'confirmation' => 'If you want, I can adjust the times.',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-19 23:40:00', $timezone));

    Task::factory()->for($user)->create([
        'title' => 'Prepare calculus worksheet',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'duration' => 60,
        'start_datetime' => null,
        'end_datetime' => CarbonImmutable::parse('2026-04-22 10:00:00', $timezone),
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Schedule my top tasks',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    $thread->refresh();

    $proposals = $assistantMessage->metadata['schedule']['proposals'] ?? [];
    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('prioritize_schedule');
    expect($assistantMessage->metadata['schedule']['confirmation_required'] ?? null)->toBeFalse();
    expect($assistantMessage->metadata['schedule']['awaiting_user_decision'] ?? null)->toBeFalse();
    expect($thread->metadata['conversation_state']['pending_schedule_fallback'] ?? null)->toBeNull();
    expect($proposals)->toBeArray()->not->toBeEmpty();
    expect($assistantMessage->metadata['schedule']['placement_digest']['default_asap_mode'] ?? null)->toBeTrue();
    expect($assistantMessage->metadata['schedule']['placement_digest']['attempted_horizon']['label'] ?? null)->toBe('default_asap_spread');

    $firstStartRaw = (string) (($proposals[0]['start_datetime'] ?? ''));
    expect($firstStartRaw)->not->toBe('');
    $firstStart = CarbonImmutable::parse($firstStartRaw, $timezone);
    expect($firstStart->toDateString())->toBe('2026-04-20');

    CarbonImmutable::setTestNow();
});

test('explicit requested date no-fit requires confirmation instead of silent day widening', function (): void {
    config([
        'task-assistant.schedule.top_n_shortfall_policy' => 'confirm_if_shortfall',
    ]);

    $thread = TaskAssistantThread::factory()->create(['user_id' => User::factory()->create()->id]);
    $service = app(TaskAssistantService::class);

    $plan = new \App\Services\LLM\TaskAssistant\ExecutionPlan(
        flow: 'schedule',
        confidence: 0.9,
        clarificationNeeded: false,
        clarificationQuestion: null,
        reasonCodes: ['llm_intent_scheduling'],
        constraints: [],
        targetEntities: [],
        timeWindowHint: null,
        countLimit: 3,
        generationProfile: 'schedule',
    );
    $scheduleData = [
        'proposals' => [],
        'placement_digest' => [
            'strict_day_requested' => true,
            'strict_day_date' => '2026-04-20',
            'placement_dates' => ['2026-04-20'],
            'days_used' => [],
            'requested_count' => 3,
            'time_window_hint' => null,
            'summary' => 'placed_proposals=0 days_used=0 unplaced_units=1',
        ],
        'framing' => 'I scheduled these for April 20th.',
        'reasoning' => 'Your requested day looks good.',
        'confirmation' => 'Do these April 20 times work for you?',
    ];

    $shouldRequire = new ReflectionMethod(TaskAssistantService::class, 'shouldRequireFallbackConfirmation');
    $shouldRequire->setAccessible(true);
    $requires = (bool) $shouldRequire->invoke($service, $plan, $scheduleData);

    expect($requires)->toBeTrue();

    $buildFallback = new ReflectionMethod(TaskAssistantService::class, 'buildScheduleFallbackConfirmationData');
    $buildFallback->setAccessible(true);
    $converted = $buildFallback->invoke($service, $scheduleData, $thread, 'actually schedule them for april 20', $plan);

    expect($converted['confirmation_required'] ?? null)->toBeTrue();
    expect($converted['awaiting_user_decision'] ?? null)->toBeTrue();
    expect($converted['confirmation_context']['reason_code'] ?? null)->toBe('explicit_day_not_feasible');
    expect((array) ($converted['confirmation_context']['options'] ?? []))
        ->toContain('Schedule for the closest available daypart')
        ->toContain('Try another time window');
});

test('fallback confirmation narrative does not claim explicit top-N for generic plan request', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'I preserved your top 3 request and prepared a draft.',
                'reasoning' => 'You asked for top 3, but only one fit.',
                'confirmation' => 'Keep this draft or adjust your window?',
                'reason_message' => 'You asked for top 3, but only one fit.',
            ])
            ->withUsage(new Usage(3, 8)),
    ]);

    $thread = TaskAssistantThread::factory()->create(['user_id' => User::factory()->create()->id]);
    $service = app(TaskAssistantService::class);

    $planForTopN = new \App\Services\LLM\TaskAssistant\ExecutionPlan(
        flow: 'schedule',
        confidence: 0.9,
        clarificationNeeded: false,
        clarificationQuestion: null,
        reasonCodes: [],
        constraints: [],
        targetEntities: [],
        timeWindowHint: null,
        countLimit: 3,
        generationProfile: 'schedule',
    );

    $scheduleData = [
        'proposals' => [[
            'proposal_id' => 'p1',
            'proposal_uuid' => 'p1',
            'status' => 'pending',
            'entity_type' => 'task',
            'entity_id' => 1,
            'title' => 'Task A',
            'start_datetime' => '2026-04-18T20:50:56+08:00',
            'end_datetime' => '2026-04-18T21:10:56+08:00',
            'duration_minutes' => 20,
            'apply_payload' => ['action' => 'update_task', 'arguments' => ['taskId' => 1, 'updates' => []]],
        ]],
        'blocks' => [[
            'start_time' => '20:50',
            'end_time' => '21:10',
            'task_id' => 1,
            'event_id' => null,
            'label' => 'Task A',
            'note' => 'Planned by strict scheduler.',
        ]],
        'items' => [[
            'title' => 'Task A',
            'entity_type' => 'task',
            'entity_id' => 1,
            'start_datetime' => '2026-04-18T20:50:56+08:00',
            'end_datetime' => '2026-04-18T21:10:56+08:00',
            'duration_minutes' => 20,
        ]],
        'framing' => 'Initial framing.',
        'reasoning' => 'Initial reasoning.',
        'confirmation' => 'Initial prompt.',
        'placement_digest' => [
            'requested_count' => 3,
            'count_shortfall' => 2,
            'top_n_shortfall' => true,
            'placement_dates' => ['2026-04-18'],
            'days_used' => ['2026-04-18'],
            'summary' => 'placed_proposals=1 days_used=1 unplaced_units=2',
        ],
    ];

    $buildFallback = new ReflectionMethod(TaskAssistantService::class, 'buildScheduleFallbackConfirmationData');
    $buildFallback->setAccessible(true);

    $converted = $buildFallback->invoke($service, $scheduleData, $thread, 'Create a plan for today', $planForTopN);

    expect($converted['confirmation_context']['requested_count_source'] ?? null)->toBe('system_default');
    expect((string) ($converted['framing'] ?? ''))->not->toContain('you asked for top 3');
    expect((string) ($converted['reasoning'] ?? ''))->not->toContain('you asked for top 3');
    expect((string) ($converted['confirmation_context']['reason_message'] ?? ''))->not->toContain('You asked for top 3');
});

test('fallback confirmation keeps horizon wording for implicit tomorrow requests', function (): void {
    $thread = TaskAssistantThread::factory()->create(['user_id' => User::factory()->create()->id]);
    $service = app(TaskAssistantService::class);

    $plan = new \App\Services\LLM\TaskAssistant\ExecutionPlan(
        flow: 'prioritize_schedule',
        confidence: 0.9,
        clarificationNeeded: false,
        clarificationQuestion: null,
        reasonCodes: ['client_action_chip_prioritize_schedule'],
        constraints: [],
        targetEntities: [],
        timeWindowHint: null,
        countLimit: 3,
        generationProfile: 'schedule',
    );

    $scheduleData = [
        'schema_version' => 2,
        'proposals' => [],
        'blocks' => [],
        'items' => [],
        'framing' => 'Initial framing.',
        'reasoning' => 'Initial reasoning.',
        'confirmation' => 'Initial prompt.',
        'requested_horizon_label' => 'tomorrow',
        'requested_window_display_label' => 'tomorrow',
        'has_explicit_clock_time' => false,
        'blocking_section_title' => 'These items are already scheduled for tomorrow:',
        'placement_digest' => [
            'requested_count' => 3,
            'requested_count_source' => 'system_default',
            'placement_dates' => ['2026-04-23'],
            'days_used' => ['2026-04-23'],
            'confirmation_signals' => [
                'triggers' => ['empty_placement'],
                'nearest_available_window' => [
                    'date' => '2026-04-24',
                    'date_label' => 'Apr 24, 2026',
                    'chip_label' => 'Apr 24',
                    'daypart' => 'afternoon',
                    'start_time' => '13:00',
                    'end_time' => '16:00',
                    'window_label' => 'afternoon',
                    'display_label' => 'Apr 24, 2026 afternoon',
                ],
            ],
        ],
    ];

    $buildFallback = new ReflectionMethod(TaskAssistantService::class, 'buildScheduleFallbackConfirmationData');
    $buildFallback->setAccessible(true);
    $converted = $buildFallback->invoke($service, $scheduleData, $thread, 'Create a plan for tomorrow', $plan);

    expect((string) ($converted['requested_window_display_label'] ?? ''))->toBe('tomorrow');
    expect((string) ($converted['confirmation_context']['requested_window_display_label'] ?? ''))->toBe('tomorrow');
    expect((string) ($converted['confirmation_context']['reason_message'] ?? ''))->not->toContain('8:00 AM');
    expect((string) ($converted['confirmation_context']['reason_message'] ?? ''))->not->toContain('22 hours');
    expect((string) ($converted['confirmation_context']['requested_horizon_label'] ?? ''))->toBe('tomorrow');
    expect((string) ($converted['confirmation_context']['option_actions'][0]['label'] ?? ''))->toBe('Schedule for Apr 24 afternoon');
    expect((string) ($converted['confirmation_context']['option_actions'][0]['label'] ?? ''))->not->toContain('PM');
});

test('pending schedule fallback proceeds on affirmative follow-up', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $task = Task::factory()->for($user)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'duration' => 60,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
    ]);

    $scheduleData = [
        'schema_version' => 2,
        'proposals' => [[
            'proposal_id' => 'p1',
            'proposal_uuid' => 'p1',
            'display_order' => 0,
            'status' => 'pending',
            'entity_type' => 'task',
            'entity_id' => $task->id,
            'title' => $task->title,
            'reason_score' => 5,
            'start_datetime' => '2026-04-03T08:00:00+08:00',
            'end_datetime' => '2026-04-03T09:00:00+08:00',
            'duration_minutes' => 60,
            'conflict_notes' => [],
            'apply_payload' => [
                'action' => 'update_task',
                'arguments' => [
                    'taskId' => $task->id,
                    'updates' => [
                        ['property' => 'startDatetime', 'value' => '2026-04-03T08:00:00+08:00'],
                        ['property' => 'duration', 'value' => '60'],
                    ],
                ],
            ],
        ]],
        'blocks' => [[
            'start_time' => '08:00',
            'end_time' => '09:00',
            'task_id' => $task->id,
            'event_id' => null,
            'label' => $task->title,
            'note' => 'Planned by strict scheduler.',
        ]],
        'items' => [[
            'title' => $task->title,
            'entity_type' => 'task',
            'entity_id' => $task->id,
            'start_datetime' => '2026-04-03T08:00:00+08:00',
            'end_datetime' => '2026-04-03T09:00:00+08:00',
            'duration_minutes' => 60,
        ]],
        'schedule_variant' => 'daily',
        'framing' => 'Fallback preview ready.',
        'reasoning' => 'Need your confirmation.',
        'confirmation' => 'Confirm fallback?',
        'schedule_empty_placement' => false,
        'placement_digest' => ['fallback_mode' => 'auto_relaxed_today_or_tomorrow'],
        'confirmation_required' => true,
        'awaiting_user_decision' => true,
        'confirmation_context' => [
            'prompt' => 'Continue tomorrow?',
            'options' => ['Yes', 'No'],
        ],
        'fallback_preview' => [
            'proposals_count' => 1,
            'days_used' => ['2026-04-03'],
            'placement_dates' => ['2026-04-03'],
            'summary' => 'placed_proposals=1 days_used=1 unplaced_units=0',
        ],
    ];

    $thread->update([
        'metadata' => [
            'conversation_state' => [
                'pending_schedule_fallback' => [
                    'schedule_data' => $scheduleData,
                    'time_window_hint' => 'later',
                    'initial_user_message' => 'schedule them later',
                ],
            ],
        ],
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'yes, sounds good',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    $thread->refresh();

    expect($assistantMessage->metadata['schedule']['confirmation_required'] ?? null)->toBeFalse();
    expect($assistantMessage->metadata['schedule']['awaiting_user_decision'] ?? null)->toBeFalse();
    expect($thread->metadata['conversation_state']['pending_schedule_fallback'] ?? null)->toBeNull();
    expect((string) $assistantMessage->content)->not->toContain('Would you like me to use this plan?');
});

test('ambiguous named scheduling prompt returns clarification and skips schedule proposals', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Task::factory()->for($user)->create([
        'title' => 'morning 5km run',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
    ]);
    Task::factory()->for($user)->create([
        'title' => 'evening 5km run',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'schedule my 5km run for tomorrow',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('general_guidance');
    expect((string) ($assistantMessage->metadata['general_guidance']['message'] ?? ''))->toContain('Please reply with the exact title');
    expect($assistantMessage->metadata['schedule'] ?? null)->toBeNull();
});

test('numeric reply resolves pending named task clarification and schedules selected option', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'I scheduled your selected task for tomorrow.',
                'reasoning' => 'This fits your requested window.',
                'confirmation' => 'Would you like any timing changes?',
            ])
            ->withUsage(new Usage(3, 7)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Task::factory()->for($user)->create([
        'title' => 'morning 5km run',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
        'duration' => 45,
    ]);
    Task::factory()->for($user)->create([
        'title' => 'evening 5km run',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
        'duration' => 60,
    ]);

    $firstUser = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'schedule my 5km run for tomorrow',
    ]);
    $firstAssistant = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);
    app(TaskAssistantService::class)->processQueuedMessage($thread, $firstUser->id, $firstAssistant->id);
    $firstAssistant->refresh();
    $thread->refresh();

    expect($firstAssistant->metadata['structured']['flow'] ?? null)->toBe('general_guidance');
    expect((string) ($firstAssistant->metadata['general_guidance']['message'] ?? ''))->toContain('1)');
    expect((string) ($firstAssistant->metadata['general_guidance']['message'] ?? ''))->toContain('2)');
    $expectedSecondOptionId = (int) ($thread->metadata['conversation_state']['pending_named_task_clarification']['candidates'][1]['entity_id'] ?? 0);
    expect($expectedSecondOptionId)->toBeGreaterThan(0);

    $secondUser = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => '2',
    ]);
    $secondAssistant = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);
    app(TaskAssistantService::class)->processQueuedMessage($thread, $secondUser->id, $secondAssistant->id);
    $secondAssistant->refresh();
    $thread->refresh();

    expect($secondAssistant->metadata['structured']['flow'] ?? null)->toBe('schedule');
    expect($thread->metadata['conversation_state']['pending_named_task_clarification'] ?? null)->toBeNull();
    expect((array) ($thread->metadata['conversation_state']['last_schedule']['target_entities'] ?? []))->toHaveCount(1);
    expect((int) ($thread->metadata['conversation_state']['last_schedule']['target_entities'][0]['entity_id'] ?? 0))->toBe($expectedSecondOptionId);
});

test('schedules multiple specifically named tasks in a single prompt (up to three)', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'I scheduled your selected tasks for today.',
                'reasoning' => 'These fit your requested window.',
                'confirmation' => 'Want me to adjust any time blocks?',
            ])
            ->withUsage(new Usage(3, 7)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $math = Task::factory()->for($user)->create([
        'title' => 'math assignment',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'duration' => 60,
    ]);
    $run = Task::factory()->for($user)->create([
        'title' => '5km run',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
        'duration' => 45,
    ]);
    $review = Task::factory()->for($user)->create([
        'title' => 'review notes',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
        'duration' => 30,
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'schedule my math assignment, 5km run, and review notes today',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);
    $assistantMessage->refresh();
    $thread->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('schedule');
    expect((string) ($assistantMessage->metadata['schedule']['named_target_resolution']['status'] ?? ''))->toBe('multi');
    expect((int) ($assistantMessage->metadata['schedule']['named_target_resolution']['resolved_count'] ?? 0))->toBe(3);
});

test('named task clarification keeps pre-resolved targets and selected ambiguous target', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'I scheduled the chosen tasks for tomorrow.',
                'reasoning' => 'This matches your requested timing.',
                'confirmation' => 'Need a different time window?',
            ])
            ->withUsage(new Usage(3, 7)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $math = Task::factory()->for($user)->create([
        'title' => 'math assignment',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'duration' => 60,
    ]);
    Task::factory()->for($user)->create([
        'title' => 'morning 5km run',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
        'duration' => 45,
    ]);
    Task::factory()->for($user)->create([
        'title' => 'evening 5km run',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
        'duration' => 60,
    ]);

    $firstUser = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'schedule my math assignment and 5km run tomorrow',
    ]);
    $firstAssistant = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $firstUser->id, $firstAssistant->id);
    $firstAssistant->refresh();
    $thread->refresh();

    expect($firstAssistant->metadata['structured']['flow'] ?? null)->toBe('general_guidance');
    expect((array) ($thread->metadata['conversation_state']['pending_named_task_clarification']['resolved_targets'] ?? []))->toHaveCount(1);
    expect((int) ($thread->metadata['conversation_state']['pending_named_task_clarification']['resolved_targets'][0]['entity_id'] ?? 0))->toBe($math->id);

    $secondUser = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'morning 5km run',
    ]);
    $secondAssistant = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $secondUser->id, $secondAssistant->id);
    $secondAssistant->refresh();
    $thread->refresh();

    expect($secondAssistant->metadata['structured']['flow'] ?? null)->toBe('schedule');
    expect((string) ($secondAssistant->metadata['schedule']['named_target_resolution']['status'] ?? ''))->toBe('multi');
    expect((int) ($secondAssistant->metadata['schedule']['named_target_resolution']['resolved_count'] ?? 0))->toBe(2);
});

test('pending schedule fallback decline asks for alternate window and clears pending state', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $task = Task::factory()->for($user)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'duration' => 60,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
    ]);

    $thread->update([
        'metadata' => [
            'conversation_state' => [
                'pending_schedule_fallback' => [
                    'schedule_data' => [
                        'schema_version' => 2,
                        'proposals' => [[
                            'proposal_id' => 'p1',
                            'proposal_uuid' => 'p1',
                            'status' => 'pending',
                            'entity_type' => 'task',
                            'entity_id' => $task->id,
                            'title' => $task->title,
                            'start_datetime' => '2026-04-03T08:00:00+08:00',
                            'end_datetime' => '2026-04-03T09:00:00+08:00',
                            'duration_minutes' => 60,
                            'apply_payload' => ['action' => 'update_task', 'arguments' => ['taskId' => $task->id, 'updates' => []]],
                        ]],
                    ],
                    'time_window_hint' => 'later',
                    'initial_user_message' => 'schedule them later',
                ],
            ],
        ],
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'no, cancel that',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    $thread->refresh();

    expect($assistantMessage->content)->toContain('No problem');
    expect($thread->metadata['conversation_state']['pending_schedule_fallback'] ?? null)->toBeNull();
});

test('pending schedule fallback accepts natural window-adjustment reply and re-routes message', function (): void {
    config([
        'task-assistant.intent.use_llm' => false,
        'task-assistant.schedule.top_n_shortfall_policy' => 'confirm_if_shortfall',
        'task-assistant.schedule.overflow_strategy' => 'require_confirm',
        'task-assistant.schedule.partial_policy' => 'top1_only',
    ]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Adjusted plan prepared.',
                'reasoning' => 'I used your updated window and scheduled your top tasks.',
                'confirmation' => 'Does this revised plan work for you?',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-17 14:00:00', $timezone));

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $tasks = Task::factory()->for($user)->count(3)->sequence(
        ['title' => 'Top long task', 'priority' => TaskPriority::Urgent, 'duration' => 300, 'end_datetime' => CarbonImmutable::parse('2026-04-18 09:00:00', $timezone)],
        ['title' => 'Second task', 'priority' => TaskPriority::High, 'duration' => 75, 'end_datetime' => CarbonImmutable::parse('2026-04-18 10:00:00', $timezone)],
        ['title' => 'Third task', 'priority' => TaskPriority::Medium, 'duration' => 20, 'end_datetime' => CarbonImmutable::parse('2026-04-18 11:00:00', $timezone)],
    )->create([
        'status' => TaskStatus::ToDo,
        'start_datetime' => null,
    ]);

    $pendingScheduleData = [
        'schema_version' => 2,
        'proposals' => [[
            'proposal_id' => 'pending-1',
            'proposal_uuid' => 'pending-1',
            'status' => 'pending',
            'entity_type' => 'task',
            'entity_id' => $tasks[0]->id,
            'title' => $tasks[0]->title,
            'reason_score' => 3,
            'start_datetime' => '2026-04-18T15:00:00+08:00',
            'end_datetime' => '2026-04-18T18:00:00+08:00',
            'duration_minutes' => 180,
            'conflict_notes' => [],
            'apply_payload' => [
                'action' => 'update_task',
                'arguments' => [
                    'taskId' => $tasks[0]->id,
                    'updates' => [
                        ['property' => 'startDatetime', 'value' => '2026-04-18T15:00:00+08:00'],
                        ['property' => 'duration', 'value' => '180'],
                    ],
                ],
            ],
            'priority_rank' => 1,
            'partial' => true,
            'requested_minutes' => 300,
            'placed_minutes' => 180,
            'placement_reason' => 'partial_fit',
            'display_order' => 0,
        ]],
        'blocks' => [[
            'start_time' => '15:00',
            'end_time' => '18:00',
            'task_id' => $tasks[0]->id,
            'event_id' => null,
            'label' => $tasks[0]->title,
            'note' => 'Planned by strict scheduler.',
        ]],
        'items' => [[
            'title' => $tasks[0]->title,
            'entity_type' => 'task',
            'entity_id' => $tasks[0]->id,
            'start_datetime' => '2026-04-18T15:00:00+08:00',
            'end_datetime' => '2026-04-18T18:00:00+08:00',
            'duration_minutes' => 180,
        ]],
        'schedule_variant' => 'daily',
        'framing' => 'Pending draft.',
        'reasoning' => 'Need user confirmation.',
        'confirmation' => 'Please confirm.',
        'schedule_empty_placement' => false,
        'placement_digest' => [
            'requested_count' => 3,
            'time_window_hint' => 'later_afternoon',
            'top_n_shortfall' => true,
            'count_shortfall' => 2,
        ],
        'confirmation_required' => true,
        'awaiting_user_decision' => true,
        'confirmation_context' => [
            'reason_code' => 'top_n_shortfall',
            'prompt' => 'Keep draft or adjust window?',
            'options' => ['Keep this current draft', 'Pick another time window', 'Cancel scheduling for now'],
        ],
        'fallback_preview' => [
            'proposals_count' => 1,
            'days_used' => ['2026-04-18'],
            'placement_dates' => ['2026-04-18'],
            'summary' => 'placed_proposals=1 days_used=1 unplaced_units=2',
        ],
    ];

    $thread->update([
        'metadata' => [
            'conversation_state' => [
                'pending_schedule_fallback' => [
                    'schedule_data' => $pendingScheduleData,
                    'time_window_hint' => 'later_afternoon',
                    'initial_user_message' => 'Schedule my top 3 tasks for tomorrow afternoon.',
                ],
            ],
        ],
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'lets adjust the window, schedule top 3 tasks for whole day tomorrow',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    $thread->refresh();

    expect((string) $assistantMessage->content)->not->toContain('Please confirm first');
    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('prioritize_schedule');
    expect($assistantMessage->metadata['schedule']['confirmation_required'] ?? null)->toBeFalse();
    expect($thread->metadata['conversation_state']['pending_schedule_fallback'] ?? null)->toBeNull();

    CarbonImmutable::setTestNow();
});

test('pending schedule fallback replan detector catches fit-all phrasing variants', function (): void {
    $service = app(TaskAssistantService::class);
    $method = new ReflectionMethod(TaskAssistantService::class, 'isLikelyScheduleReplanRequest');
    $method->setAccessible(true);

    $pendingState = [
        'schedule_data' => [
            'confirmation_context' => [
                'requested_count' => 3,
            ],
            'placement_digest' => [
                'requested_count' => 3,
            ],
        ],
    ];

    $fitAllLater = (bool) $method->invoke(
        $service,
        'try fitting all of them for later',
        $pendingState
    );
    $fitAllCount = (bool) $method->invoke(
        $service,
        'i said try fitting all 3',
        $pendingState
    );

    expect($fitAllLater)->toBeTrue();
    expect($fitAllCount)->toBeTrue();
});

test('pending schedule fallback fit-all follow-ups reroute instead of looping clarification', function (): void {
    config([
        'task-assistant.intent.use_llm' => false,
        'task-assistant.schedule.top_n_shortfall_policy' => 'confirm_if_shortfall',
        'task-assistant.schedule.overflow_strategy' => 'require_confirm',
        'task-assistant.schedule.partial_policy' => 'top1_only',
    ]);

    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-17 14:00:00', $timezone));

    $user = User::factory()->create();

    foreach ([
        'try fitting all of them for later',
        'i said try fitting all 3',
    ] as $followupPrompt) {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured([
                    'framing' => 'Replanned schedule prepared.',
                    'reasoning' => 'I retried placement using your follow-up instruction.',
                    'confirmation' => 'Tell me if you want another adjustment.',
                ])
                ->withUsage(new Usage(5, 10)),
        ]);

        $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
        $task = Task::factory()->for($user)->create([
            'status' => TaskStatus::ToDo,
            'priority' => TaskPriority::Urgent,
            'duration' => 120,
            'start_datetime' => null,
            'end_datetime' => CarbonImmutable::parse('2026-04-18 11:00:00', $timezone),
        ]);

        $pendingScheduleData = [
            'schema_version' => 2,
            'proposals' => [[
                'proposal_id' => 'pending-1',
                'proposal_uuid' => 'pending-1',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => $task->id,
                'title' => $task->title,
                'reason_score' => 2,
                'start_datetime' => '2026-04-18T16:00:00+08:00',
                'end_datetime' => '2026-04-18T18:00:00+08:00',
                'duration_minutes' => 120,
                'conflict_notes' => [],
                'apply_payload' => [
                    'action' => 'update_task',
                    'arguments' => [
                        'taskId' => $task->id,
                        'updates' => [
                            ['property' => 'startDatetime', 'value' => '2026-04-18T16:00:00+08:00'],
                            ['property' => 'duration', 'value' => '120'],
                        ],
                    ],
                ],
                'priority_rank' => 1,
                'display_order' => 0,
            ]],
            'blocks' => [[
                'start_time' => '16:00',
                'end_time' => '18:00',
                'task_id' => $task->id,
                'event_id' => null,
                'label' => $task->title,
                'note' => 'Planned by strict scheduler.',
            ]],
            'items' => [[
                'title' => $task->title,
                'entity_type' => 'task',
                'entity_id' => $task->id,
                'start_datetime' => '2026-04-18T16:00:00+08:00',
                'end_datetime' => '2026-04-18T18:00:00+08:00',
                'duration_minutes' => 120,
            ]],
            'schedule_variant' => 'daily',
            'framing' => 'Pending fallback draft.',
            'reasoning' => 'Need user confirmation.',
            'confirmation' => 'Please confirm.',
            'schedule_empty_placement' => false,
            'placement_digest' => [
                'requested_count' => 3,
                'time_window_hint' => 'later',
                'top_n_shortfall' => true,
                'count_shortfall' => 2,
            ],
            'confirmation_required' => true,
            'awaiting_user_decision' => true,
            'confirmation_context' => [
                'reason_code' => 'top_n_shortfall',
                'requested_count' => 3,
                'prompt' => 'Keep draft or adjust window?',
                'options' => ['Keep this current draft', 'Pick another time window', 'Cancel scheduling for now'],
            ],
            'fallback_preview' => [
                'proposals_count' => 1,
                'days_used' => ['2026-04-18'],
                'placement_dates' => ['2026-04-18'],
                'summary' => 'placed_proposals=1 days_used=1 unplaced_units=2',
            ],
        ];

        $thread->update([
            'metadata' => [
                'conversation_state' => [
                    'pending_schedule_fallback' => [
                        'schedule_data' => $pendingScheduleData,
                        'time_window_hint' => 'later',
                        'initial_user_message' => 'create a plan for today',
                    ],
                ],
            ],
        ]);

        $userMessage = $thread->messages()->create([
            'role' => MessageRole::User,
            'content' => $followupPrompt,
        ]);
        $assistantMessage = $thread->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => '',
        ]);

        app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

        $assistantMessage->refresh();
        $thread->refresh();

        $flow = (string) ($assistantMessage->metadata['structured']['flow'] ?? '');
        expect((string) $assistantMessage->content)->not->toContain('Please confirm first');
        expect(in_array($flow, ['schedule', 'prioritize_schedule', 'general_guidance'], true))->toBeTrue();
        expect($assistantMessage->metadata['schedule']['confirmation_required'] ?? false)->toBeFalse();
        expect($assistantMessage->metadata['schedule']['awaiting_user_decision'] ?? false)->toBeFalse();
        expect($thread->metadata['conversation_state']['pending_schedule_fallback'] ?? null)->toBeNull();
    }

    CarbonImmutable::setTestNow();
});

test('pending schedule fallback releases when user pivots to fresh prioritize request', function (): void {
    config([
        'task-assistant.intent.use_llm' => false,
    ]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'items' => [[
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'Top task',
                    'reason' => 'Most urgent right now.',
                ]],
                'limit_used' => 1,
                'doing_progress_coach' => false,
                'focus' => 'Start with the highest urgency.',
                'acknowledgment' => 'Got it.',
                'framing' => 'Here is your top priority.',
                'reasoning' => 'I ranked by urgency and due pressure.',
                'next_options' => [],
                'next_options_chip_texts' => [],
                'filter_interpretation' => null,
                'assumptions' => [],
                'count_mismatch_explanation' => null,
                'prioritize_variant' => 'rank',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Task::factory()->for($user)->count(3)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'start_datetime' => null,
    ]);

    $thread->update([
        'metadata' => [
            'conversation_state' => [
                'pending_schedule_fallback' => [
                    'time_window_hint' => 'later',
                    'initial_user_message' => 'schedule my top 3 later',
                    'schedule_data' => [
                        'schema_version' => 2,
                        'proposals' => [],
                        'blocks' => [],
                        'items' => [],
                        'confirmation_required' => true,
                        'awaiting_user_decision' => true,
                        'confirmation_context' => [
                            'requested_count' => 3,
                            'prompt' => 'Keep draft or adjust window?',
                            'options' => ['Use this draft', 'Pick another time window', 'Cancel scheduling for now'],
                        ],
                        'placement_digest' => [
                            'requested_count' => 3,
                            'top_n_shortfall' => true,
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'what should i do first right now',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    $thread->refresh();

    expect((string) $assistantMessage->content)->not->toContain('Please confirm first');
    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('prioritize');
    expect($thread->metadata['conversation_state']['pending_schedule_fallback'] ?? null)->toBeNull();
});

test('pending fallback chip action try_nearest_available_window executes deterministic schedule path', function (): void {
    config(['task-assistant.intent.use_llm' => false]);
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-17 21:30:00', $timezone));

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'I prepared a schedule for tomorrow morning.',
                'reasoning' => 'I used your selected fallback option and placed what could fit.',
                'confirmation' => 'Want me to adjust anything else?',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $task = Task::factory()->for($user)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'duration' => 30,
        'start_datetime' => null,
        'end_datetime' => CarbonImmutable::parse('2026-04-18 18:00:00', $timezone),
    ]);

    $thread->update([
        'metadata' => [
            'conversation_state' => [
                'pending_schedule_fallback' => [
                    'time_window_hint' => 'later',
                    'schedule_data' => [
                        'schema_version' => 2,
                        'proposals' => [],
                        'blocks' => [],
                        'items' => [],
                        'placement_digest' => [
                            'requested_count' => 1,
                        ],
                        'confirmation_context' => [
                            'requested_count' => 1,
                            'option_actions' => [
                                ['id' => 'try_nearest_available_window', 'label' => 'Try tomorrow morning'],
                                ['id' => 'pick_another_time_window', 'label' => 'Pick another time window'],
                                ['id' => 'cancel_scheduling', 'label' => 'Cancel scheduling for now'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Try tomorrow morning',
        'metadata' => [
            'client_action' => [
                'id' => 'try_nearest_available_window',
                'source' => 'fallback_option_chip',
            ],
        ],
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    $thread->refresh();

    expect($thread->metadata['conversation_state']['pending_schedule_fallback'] ?? null)->toBeNull();
    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('schedule');
    expect($assistantMessage->metadata['routing_trace']['final_flow'] ?? null)->toBe('schedule');
    expect($assistantMessage->metadata['routing_trace']['initial_reason_codes'] ?? [])
        ->toContain('fallback_action_try_closest_available_window');

    CarbonImmutable::setTestNow();
});

test('pending fallback chip action pick_another_time_window executes deterministic prioritize_schedule path', function (): void {
    config(['task-assistant.intent.use_llm' => false]);
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-17 21:30:00', $timezone));

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'I prioritized and placed your top tasks later this week.',
                'reasoning' => 'I ranked the best candidates first, then scheduled them into nearby open windows.',
                'confirmation' => 'If you want, I can refine this schedule.',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    Task::factory()->for($user)->count(3)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'duration' => 45,
        'start_datetime' => null,
    ]);

    $thread->update([
        'metadata' => [
            'conversation_state' => [
                'pending_schedule_fallback' => [
                    'time_window_hint' => 'later',
                    'schedule_data' => [
                        'schema_version' => 2,
                        'proposals' => [],
                        'blocks' => [],
                        'items' => [],
                        'placement_digest' => [
                            'requested_count' => 3,
                        ],
                        'confirmation_context' => [
                            'requested_count' => 3,
                            'option_actions' => [
                                ['id' => 'pick_another_time_window', 'label' => 'Schedule them later this week instead'],
                                ['id' => 'cancel_scheduling', 'label' => 'Cancel scheduling for now'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Schedule them later this week instead',
        'metadata' => [
            'client_action' => [
                'id' => 'pick_another_time_window',
                'source' => 'fallback_option_chip',
            ],
        ],
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    $thread->refresh();

    expect($thread->metadata['conversation_state']['pending_schedule_fallback'] ?? null)->toBeNull();
    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('prioritize_schedule');
    expect($assistantMessage->metadata['routing_trace']['final_flow'] ?? null)->toBe('prioritize_schedule');
    expect($assistantMessage->metadata['routing_trace']['initial_reason_codes'] ?? [])
        ->toContain('fallback_action_prioritize_schedule_later_this_week');

    CarbonImmutable::setTestNow();
});

test('processQueuedMessage creates one assistant response ready notification and avoids duplicates on rerun', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    Prism::fake([
        TextResponseFake::make()
            ->withText('Here is your plan.')
            ->withFinishReason(FinishReason::Stop)
            ->withToolCalls([])
            ->withToolResults([])
            ->withUsage(new Usage(1, 2))
            ->withMeta(new Meta('fake', 'fake')),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'help me prioritize',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    $service = app(TaskAssistantService::class);
    $service->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    expect(data_get($assistantMessage->metadata, 'notifications.assistant_response_ready_at'))->toBeString();

    $countAfterFirstRun = DatabaseNotification::query()
        ->where('notifiable_type', User::class)
        ->where('notifiable_id', $user->id)
        ->where('type', AssistantResponseReadyNotification::class)
        ->count();

    expect($countAfterFirstRun)->toBe(1);

    $service->processQueuedMessage($thread->fresh(), $userMessage->id, $assistantMessage->id);

    $countAfterSecondRun = DatabaseNotification::query()
        ->where('notifiable_type', User::class)
        ->where('notifiable_id', $user->id)
        ->where('type', AssistantResponseReadyNotification::class)
        ->count();

    expect($countAfterSecondRun)->toBe(1);
});

test('processQueuedMessage routes prioritize top-three chip actions deterministically without intent inference', function (): void {
    config(['task-assistant.intent.use_llm' => true]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Show next 3',
        'metadata' => [
            'client_action' => [
                'id' => 'chip_prioritize_top_three',
                'source' => 'next_option_chip',
            ],
        ],
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('prioritize');
    expect($assistantMessage->metadata['routing_trace']['initial_reason_codes'] ?? [])
        ->toContain('client_action_chip_prioritize_top_three');
});

test('processQueuedMessage routes prioritize-schedule top-one chip actions deterministically without intent inference', function (): void {
    config(['task-assistant.intent.use_llm' => true]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Schedule top 1 for later',
        'metadata' => [
            'client_action' => [
                'id' => 'chip_prioritize_schedule_top_one',
                'source' => 'next_option_chip',
            ],
        ],
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['routing_trace']['initial_flow'] ?? null)->toBe('prioritize_schedule');
    expect($assistantMessage->metadata['routing_trace']['initial_reason_codes'] ?? [])
        ->toContain('client_action_chip_prioritize_schedule_top_one');
});

test('deterministic prioritize multi-item chips include top-task-later option', function (): void {
    $service = app(TaskAssistantService::class);
    $method = new ReflectionMethod(TaskAssistantService::class, 'buildDeterministicPrioritizeNextOptions');
    $method->setAccessible(true);

    $multiItemResult = $method->invoke($service, [
        ['title' => 'Task A'],
        ['title' => 'Task B'],
    ], true);
    $singleItemResult = $method->invoke($service, [
        ['title' => 'Task A'],
    ], true);

    expect($multiItemResult['next_options_chip_texts'] ?? [])
        ->toContain('Schedule those tasks for later today')
        ->toContain('Schedule those tasks for tomorrow')
        ->toContain('Schedule only the top task for later');

    expect($singleItemResult['next_options_chip_texts'] ?? [])
        ->not->toContain('Schedule only the top task for later');
});

test('processQueuedMessage routes ranked-set schedule chip actions deterministically', function (): void {
    config(['task-assistant.intent.use_llm' => true]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Schedule those tasks for later today',
        'metadata' => [
            'client_action' => [
                'id' => 'chip_schedule_ranked_set',
                'source' => 'next_option_chip',
            ],
        ],
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['routing_trace']['initial_flow'] ?? null)->toBe('schedule');
    expect($assistantMessage->metadata['routing_trace']['initial_reason_codes'] ?? [])
        ->toContain('client_action_chip_schedule_ranked_set');
});

test('ranked-set schedule chip reuses last listing targets as strict set contract', function (): void {
    config([
        'task-assistant.intent.use_llm' => false,
        'task-assistant.schedule.top_n_shortfall_policy' => 'confirm_if_shortfall',
        'task-assistant.schedule.overflow_strategy' => 'require_confirm',
        'task-assistant.schedule.partial_policy' => 'top1_only',
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-23 19:40:00', $timezone));

    $first = Task::factory()->for($user)->create([
        'title' => 'Task One',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Urgent,
        'duration' => 60,
        'start_datetime' => null,
        'end_datetime' => CarbonImmutable::parse('2026-04-24 09:00:00', $timezone),
    ]);
    $second = Task::factory()->for($user)->create([
        'title' => 'Task Two',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'duration' => 60,
        'start_datetime' => null,
        'end_datetime' => CarbonImmutable::parse('2026-04-24 10:00:00', $timezone),
    ]);
    $third = Task::factory()->for($user)->create([
        'title' => 'Task Three',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
        'duration' => 60,
        'start_datetime' => null,
        'end_datetime' => CarbonImmutable::parse('2026-04-24 11:00:00', $timezone),
    ]);

    app(\App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService::class)->rememberLastListing(
        $thread,
        'prioritize',
        [
            ['entity_type' => 'task', 'entity_id' => $first->id, 'title' => $first->title],
            ['entity_type' => 'task', 'entity_id' => $second->id, 'title' => $second->title],
            ['entity_type' => 'task', 'entity_id' => $third->id, 'title' => $third->title],
        ],
        null,
        3,
    );

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Schedule those tasks for later today',
        'metadata' => [
            'client_action' => [
                'id' => 'chip_schedule_ranked_set',
                'source' => 'next_option_chip',
            ],
        ],
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    $digest = is_array($assistantMessage->metadata['schedule']['placement_digest'] ?? null)
        ? $assistantMessage->metadata['schedule']['placement_digest']
        : [];

    expect($assistantMessage->metadata['routing_trace']['initial_reason_codes'] ?? [])
        ->toContain('client_action_chip_schedule_ranked_set');
    expect($digest['requested_count'] ?? null)->toBe(3);
    expect($digest['requested_count_source'] ?? null)->toBe('explicit_user');
    expect($digest['is_strict_set_contract'] ?? null)->toBeTrue();

    CarbonImmutable::setTestNow();
});

test('processQueuedMessage routes ranked-top-one schedule chip actions deterministically', function (): void {
    config(['task-assistant.intent.use_llm' => true]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Schedule that task for later today',
        'metadata' => [
            'client_action' => [
                'id' => 'chip_schedule_ranked_top_one',
                'source' => 'next_option_chip',
            ],
        ],
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['routing_trace']['initial_flow'] ?? null)->toBe('schedule');
    expect($assistantMessage->metadata['routing_trace']['initial_reason_codes'] ?? [])
        ->toContain('client_action_chip_schedule_ranked_top_one');
});
