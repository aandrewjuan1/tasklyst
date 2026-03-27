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

it('supports prioritize to schedule multiturn using pronoun reference', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Here are your top priorities in order.',
                'acknowledgment' => null,
                'reasoning' => 'Ordered by urgency and due timing.',
                'next_options' => 'If you want, I can schedule these steps for later.',
                'next_options_chip_texts' => ['Schedule these'],
            ])
            ->withUsage(new Usage(1, 1)),
        StructuredResponseFake::make()
            ->withStructured([
                'summary' => 'Afternoon schedule prepared.',
                'assistant_note' => 'You can start with the first task at 3 PM.',
                'reasoning' => 'This fits your request.',
                'strategy_points' => ['Front-load urgent work.'],
                'suggested_next_steps' => ['Accept proposals to apply updates.'],
                'assumptions' => [],
            ])
            ->withUsage(new Usage(1, 1)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Task::factory()->for($user)->count(4)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
        'duration' => 45,
    ]);

    $service = app(TaskAssistantService::class);

    $firstUser = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'show my top tasks',
    ]);
    $firstAssistant = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);
    $service->processQueuedMessage($thread, $firstUser->id, $firstAssistant->id);

    $secondUser = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'schedule those',
    ]);
    $secondAssistant = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);
    $service->processQueuedMessage($thread, $secondUser->id, $secondAssistant->id);

    $firstAssistant->refresh();
    $secondAssistant->refresh();
    $thread->refresh();

    expect(data_get($firstAssistant->metadata, 'structured.flow'))->toBe('prioritize');
    expect(data_get($secondAssistant->metadata, 'structured.flow'))->toBe('schedule');
    expect(data_get($thread->metadata, 'conversation_state.last_listing.items'))->toBeArray()->not->toBeEmpty();
    expect(data_get($secondAssistant->metadata, 'schedule.proposals'))->toBeArray();
});

it('supports single-item prioritize then schedule this', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Start with this top task first.',
                'acknowledgment' => null,
                'reasoning' => 'This is the highest urgency item.',
                'next_options' => 'If you want, I can schedule this for later.',
                'next_options_chip_texts' => ['Schedule this'],
            ])
            ->withUsage(new Usage(1, 1)),
        StructuredResponseFake::make()
            ->withStructured([
                'summary' => 'Single-task schedule prepared.',
                'assistant_note' => 'One focused block is enough for now.',
                'reasoning' => 'This fits the single-task request.',
                'strategy_points' => ['Protect one uninterrupted block.'],
                'suggested_next_steps' => ['Accept the proposal.'],
                'assumptions' => [],
            ])
            ->withUsage(new Usage(1, 1)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Task::factory()->for($user)->count(3)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'start_datetime' => null,
        'end_datetime' => now()->addDays(2),
        'duration' => 30,
    ]);

    $service = app(TaskAssistantService::class);

    $firstUser = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'what should i do first',
    ]);
    $firstAssistant = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);
    $service->processQueuedMessage($thread, $firstUser->id, $firstAssistant->id);

    $firstAssistant->refresh();
    expect(data_get($firstAssistant->metadata, 'prioritize.items'))->toHaveCount(1);

    $secondUser = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'schedule this',
    ]);
    $secondAssistant = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);
    $service->processQueuedMessage($thread, $secondUser->id, $secondAssistant->id);

    $secondAssistant->refresh();
    expect(data_get($secondAssistant->metadata, 'structured.flow'))->toBe('schedule');
    expect(data_get($secondAssistant->metadata, 'schedule.proposals'))->toBeArray();
});

it('filters chores in prioritize then schedules only those targets', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Here are the chores to handle first.',
                'acknowledgment' => null,
                'reasoning' => 'Filtered to chores and ordered by urgency.',
                'next_options' => 'If you want, I can schedule these tasks for tonight.',
                'next_options_chip_texts' => ['Schedule these'],
            ])
            ->withUsage(new Usage(1, 1)),
        StructuredResponseFake::make()
            ->withStructured([
                'summary' => 'Tonight chores schedule prepared.',
                'assistant_note' => 'Keep the blocks short and focused.',
                'reasoning' => 'This plan fits your chores request.',
                'strategy_points' => ['Start with quick household wins.'],
                'suggested_next_steps' => ['Accept proposals to apply updates.'],
                'assumptions' => [],
            ])
            ->withUsage(new Usage(1, 1)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Task::factory()->for($user)->create([
        'title' => 'Wash dishes after dinner',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Low,
        'subject_name' => null,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
        'duration' => 20,
    ]);
    Task::factory()->for($user)->create([
        'title' => 'Prepare tomorrow\'s school bag',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
        'subject_name' => null,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
        'duration' => 15,
    ]);
    Task::factory()->for($user)->create([
        'title' => 'CS 220 - Lab 5: Linked Lists',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'subject_name' => 'CS 220 - Data Structures',
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
        'duration' => 120,
    ]);

    $service = app(TaskAssistantService::class);

    $firstUser = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'show me chores i should do first',
    ]);
    $firstAssistant = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);
    $service->processQueuedMessage($thread, $firstUser->id, $firstAssistant->id);

    $firstAssistant->refresh();
    $items = data_get($firstAssistant->metadata, 'prioritize.items', []);

    expect($items)->toBeArray()->not->toBeEmpty();
    $titles = collect($items)->map(fn (array $row): string => (string) ($row['title'] ?? ''))->all();
    foreach ($titles as $title) {
        expect((bool) preg_match('/wash|bag|steps|drawing/i', $title))->toBeTrue();
    }

    $secondUser = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'schedule them tonight',
    ]);
    $secondAssistant = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);
    $service->processQueuedMessage($thread, $secondUser->id, $secondAssistant->id);

    $secondAssistant->refresh();
    $thread->refresh();
    expect(data_get($secondAssistant->metadata, 'structured.flow'))->toBe('schedule');
    expect(data_get($thread->metadata, 'conversation_state.last_schedule.target_entities'))->toBeArray()->not->toBeEmpty();
});
