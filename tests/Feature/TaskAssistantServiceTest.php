<?php

use App\Enums\MessageRole;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
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
                'intent' => 'scheduling',
                'confidence' => 0.97,
                'rationale' => 'User asked to schedule.',
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

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('prioritize');
    expect($assistantMessage->metadata['prioritize']['items'] ?? null)->toBeArray();
});

test('edit-like turn after pending schedule draft rewrites prioritize intent to schedule refinement', function (): void {
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

    $seedAssistant = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Draft schedule',
        'metadata' => [
            'schedule' => [
                'proposals' => [
                    [
                        'proposal_id' => 'a',
                        'status' => 'pending',
                        'entity_type' => 'task',
                        'entity_id' => 1,
                        'title' => 'Task A',
                        'start_datetime' => '2026-04-02T08:00:00+08:00',
                        'end_datetime' => '2026-04-02T09:00:00+08:00',
                        'duration_minutes' => 60,
                        'apply_payload' => ['tool' => 'update_task', 'arguments' => ['taskId' => 1, 'updates' => []]],
                    ],
                    [
                        'proposal_id' => 'b',
                        'status' => 'pending',
                        'entity_type' => 'task',
                        'entity_id' => 2,
                        'title' => 'Task B',
                        'start_datetime' => '2026-04-02T09:30:00+08:00',
                        'end_datetime' => '2026-04-02T10:00:00+08:00',
                        'duration_minutes' => 30,
                        'apply_payload' => ['tool' => 'update_task', 'arguments' => ['taskId' => 2, 'updates' => []]],
                    ],
                ],
            ],
            'structured' => [
                'flow' => 'schedule',
            ],
        ],
    ]);

    expect($seedAssistant->id)->toBeInt();

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
    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('schedule');
    expect($assistantMessage->metadata['schedule']['proposals'] ?? null)->toBeArray();
    expect($assistantMessage->metadata['schedule']['proposals'][0]['proposal_id'] ?? null)->toBe('b');
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
                    'apply_payload' => ['tool' => 'update_task', 'arguments' => ['taskId' => 1, 'updates' => []]],
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
    expect(trim((string) $assistantMessage->content))->not->toBe('');

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($thread, $assistantMessage): bool {
        return $message === 'task-assistant.routing_decision'
            && ($context['thread_id'] ?? null) === $thread->id
            && ($context['assistant_message_id'] ?? null) === $assistantMessage->id
            && is_string($context['run_id'] ?? null)
            && ($context['flow'] ?? null) === 'general_guidance';
    })->atLeast()->once();
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
    expect($thread->metadata['conversation_state']['pending_schedule_fallback'] ?? null)->toBeArray();
    CarbonImmutable::setTestNow();
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
                'tool' => 'update_task',
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
                            'apply_payload' => ['tool' => 'update_task', 'arguments' => ['taskId' => $task->id, 'updates' => []]],
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
