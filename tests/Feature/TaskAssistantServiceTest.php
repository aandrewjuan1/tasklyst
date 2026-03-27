<?php

use App\Enums\MessageRole;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
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
                'summary' => 'Afternoon-focused plan.',
                'assistant_note' => 'Start at 3 PM with your highest-impact item.',
                'reasoning' => 'This aligns with your requested window.',
                'strategy_points' => ['Front-load important work.'],
                'suggested_next_steps' => ['Accept proposals to apply scheduling updates.'],
                'assumptions' => [],
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

test('prioritize follow-up show next 3 excludes previously shown items', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Here are your top priorities in a simple order you can start now.',
                'acknowledgment' => null,
                'reasoning' => 'This ordering helps you start with the most urgent work first.',
                'next_options' => 'If you want, I can schedule this for later, or show your next 3 priorities.',
                'next_options_chip_texts' => ['Schedule this', 'Show next 3'],
            ])
            ->withUsage(new Usage(5, 10)),
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Here are the next priorities from your list.',
                'acknowledgment' => null,
                'reasoning' => 'These are the next highest-ranked unseen items.',
                'next_options' => 'If you want, I can schedule time for these.',
                'next_options_chip_texts' => ['Schedule these'],
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Task::factory()->for($user)->count(6)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
    ]);

    $service = app(TaskAssistantService::class);

    $firstUser = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'What should I do first?',
    ]);
    $firstAssistant = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);
    $service->processQueuedMessage($thread, $firstUser->id, $firstAssistant->id);

    $secondUser = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'show next 3',
    ]);
    $secondAssistant = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);
    $service->processQueuedMessage($thread, $secondUser->id, $secondAssistant->id);

    $firstAssistant->refresh();
    $secondAssistant->refresh();
    $thread->refresh();

    $firstItems = $firstAssistant->metadata['prioritize']['items'] ?? [];
    $secondItems = $secondAssistant->metadata['prioritize']['items'] ?? [];
    $firstNextOptions = (string) ($firstAssistant->metadata['prioritize']['next_options'] ?? '');
    $secondNextOptions = (string) ($secondAssistant->metadata['prioritize']['next_options'] ?? '');
    $firstChips = $firstAssistant->metadata['prioritize']['next_options_chip_texts'] ?? [];
    $secondChips = $secondAssistant->metadata['prioritize']['next_options_chip_texts'] ?? [];

    $firstKeys = collect($firstItems)->map(fn (array $row): string => $row['entity_type'].':'.$row['entity_id'])->values()->all();
    $secondKeys = collect($secondItems)->map(fn (array $row): string => $row['entity_type'].':'.$row['entity_id'])->values()->all();

    expect($firstAssistant->metadata['structured']['flow'] ?? null)->toBe('prioritize');
    expect($firstItems)->toHaveCount(1);
    expect($secondItems)->toHaveCount(3);
    expect(array_intersect($firstKeys, $secondKeys))->toBe([]);
    expect($thread->metadata['conversation_state']['prioritize_pagination']['shown_entity_keys'] ?? [])
        ->toHaveCount(4);
    expect($firstNextOptions)->toContain('schedule this task for later today, tomorrow, or this week');
    expect($secondNextOptions)->toContain('schedule these tasks for later today, tomorrow, or this week');
    expect($firstNextOptions)->not->toContain('task(s)');
    expect($secondNextOptions)->not->toContain('task(s)');
    expect($firstNextOptions)->not->toContain('show your next 3 priorities');
    expect($secondNextOptions)->not->toContain('show your next 3 priorities');
    expect($firstChips)->toBe(['Later today', 'Tomorrow', 'This week']);
    expect($secondChips)->toBe(['Later today', 'Tomorrow', 'This week']);
});

test('prioritize follow-up show next 3 inherits task preference (does not reintroduce events)', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Here are your top priorities.',
                'acknowledgment' => null,
                'reasoning' => 'Ranked by urgency.',
                'next_options' => 'If you want, I can schedule time for these, or show your next 3 priorities.',
                'next_options_chip_texts' => ['Schedule these', 'Show next 3'],
            ])
            ->withUsage(new Usage(5, 10)),
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Here are the next priorities from your list.',
                'acknowledgment' => null,
                'reasoning' => 'Next unseen items.',
                'next_options' => 'If you want, I can schedule time for these.',
                'next_options_chip_texts' => ['Schedule these'],
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Task::factory()->for($user)->count(6)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
    ]);

    // Ensure at least one event exists in the candidate snapshot window.
    \App\Models\Event::factory()->for($user)->create([
        'start_datetime' => now()->addMinutes(30),
        'end_datetime' => now()->addMinutes(90),
    ]);

    $service = app(TaskAssistantService::class);

    $firstUser = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'what about the top 3 tasks that i need to do asap',
    ]);
    $firstAssistant = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);
    $service->processQueuedMessage($thread, $firstUser->id, $firstAssistant->id);

    $secondUser = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'okay show the next 3',
    ]);
    $secondAssistant = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);
    $service->processQueuedMessage($thread, $secondUser->id, $secondAssistant->id);

    $secondAssistant->refresh();

    $secondItems = $secondAssistant->metadata['prioritize']['items'] ?? [];
    $secondTypes = collect($secondItems)->map(fn (array $row): string => (string) ($row['entity_type'] ?? ''))->values()->all();
    $firstNextOptions = (string) ($firstAssistant->metadata['prioritize']['next_options'] ?? '');
    $secondNextOptions = (string) ($secondAssistant->metadata['prioritize']['next_options'] ?? '');
    $firstChips = $firstAssistant->metadata['prioritize']['next_options_chip_texts'] ?? [];
    $secondChips = $secondAssistant->metadata['prioritize']['next_options_chip_texts'] ?? [];

    expect($secondItems)->toHaveCount(3);
    expect($secondTypes)->each->toBe('task');
    expect($secondNextOptions)->toContain('schedule these tasks for later today, tomorrow, or this week');
    expect($secondNextOptions)->not->toContain('task(s)');
    expect($secondNextOptions)->not->toContain('show your next 3 priorities');
    expect($secondChips)->toBe(['Later today', 'Tomorrow', 'This week']);
});
