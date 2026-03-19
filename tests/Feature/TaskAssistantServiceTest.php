<?php

use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use App\Services\LLM\TaskAssistant\TaskAssistantTaskChoiceRunner;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;

test('stream response creates user and assistant messages and updates assistant content from stream', function () {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Prism::fake([
        TextResponseFake::make()
            ->withText('Fake assistant reply')
            ->withUsage(new Usage(10, 20)),
    ]);

    $service = app(TaskAssistantService::class);
    $response = $service->streamResponse($thread, 'Hello');

    expect($response->getStatusCode())->toBe(200);

    ob_start();
    $response->sendContent();
    ob_end_clean();

    $thread->refresh();
    $messages = $thread->messages()->orderBy('id')->get();

    expect($messages)->toHaveCount(2);
    expect($messages[0]->role->value)->toBe('user');
    expect($messages[0]->content)->toBe('Hello');
    expect($messages[1]->role->value)->toBe('assistant');
    expect($messages[1]->content)->toBe('Fake assistant reply');
});

test('stream response with existing history runs and updates assistant message', function () {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $thread->messages()->create([
        'role' => \App\Enums\MessageRole::User,
        'content' => 'List my tasks',
    ]);
    $thread->messages()->create([
        'role' => \App\Enums\MessageRole::Assistant,
        'content' => '',
        'tool_calls' => [
            ['id' => 'call_1', 'name' => 'list_tasks', 'arguments' => []],
        ],
    ]);
    $thread->messages()->create([
        'role' => \App\Enums\MessageRole::Tool,
        'content' => '{"tasks":[]}',
        'metadata' => ['tool_call_id' => 'call_1', 'tool_name' => 'list_tasks'],
    ]);

    Prism::fake([
        TextResponseFake::make()
            ->withText('Here are your tasks.')
            ->withUsage(new Usage(5, 10)),
    ]);

    $service = app(TaskAssistantService::class);
    $response = $service->streamResponse($thread, 'Thanks');

    ob_start();
    $response->sendContent();
    ob_end_clean();

    $thread->refresh();
    $assistantMessages = $thread->messages()->where('role', 'assistant')->orderBy('id')->get();
    $lastAssistant = $assistantMessages->last();

    expect($lastAssistant->content)->toBe('Here are your tasks.');
});

test('broadcast stream updates assistant message and can record llm_tool_calls', function () {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $userMessage = $thread->messages()->create([
        'role' => \App\Enums\MessageRole::User,
        'content' => 'Hello',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => \App\Enums\MessageRole::Assistant,
        'content' => '',
    ]);

    Prism::fake([
        TextResponseFake::make()
            ->withText('Broadcast reply')
            ->withUsage(new Usage(5, 10)),
    ]);

    $service = app(TaskAssistantService::class);
    $service->broadcastStream($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();
    expect($assistantMessage->content)->toBe('Broadcast reply');
});

test('runTaskChoice stores validated structured task choice data', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $task = Task::factory()->for($user)->create([
        'title' => 'Read chapter 1',
        'status' => \App\Enums\TaskStatus::ToDo,
        'priority' => \App\Enums\TaskPriority::High,
        'end_datetime' => now()->addDay(),
    ]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent_type' => 'general',
                'priority_filters' => [],
                'task_keywords' => [],
                'time_constraint' => null,
                'comparison_focus' => null,
            ])
            ->withUsage(new Usage(5, 10)),
        StructuredResponseFake::make()
            ->withStructured([
                'suggestion' => 'Focus on reading next.',
                'reason' => 'It will move you forward quickly.',
                'steps' => [
                    'Skim the headings first.',
                    'Read actively for 20 minutes.',
                    'Write down 3 key points.',
                ],
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $service = app(TaskAssistantService::class);
    $runner = app(TaskAssistantTaskChoiceRunner::class);

    $result = $service->runTaskChoice($thread, 'Help me choose what to work on next.', $runner);

    expect($result['valid'])->toBeTrue();
    expect($result['data']['chosen_type'])->toBe('task');
    expect($result['data']['chosen_id'])->toBe($task->id);
    expect($result['data']['chosen_title'])->toBe('Read chapter 1');
    expect($result['data']['suggestion'])->toBe('Focus on reading next.');

    $thread->refresh();
    $assistantMessage = $thread->messages()
        ->where('role', 'assistant')
        ->latest('id')
        ->first();

    expect($assistantMessage)->not->toBeNull();
    expect($assistantMessage->metadata['task_choice']['chosen_task_id'] ?? null)->toBe($task->id);
    expect($assistantMessage->metadata['processed'] ?? false)->toBeTrue();
});

test('task_choice queued path matches sync formatted output', function (): void {
    $user = User::factory()->create();

    Task::factory()->for($user)->create([
        'title' => 'Read chapter 1',
        'status' => \App\Enums\TaskStatus::ToDo,
        'priority' => \App\Enums\TaskPriority::High,
        'end_datetime' => now()->addDay(),
    ]);

    Prism::fake([
        // sync: context analysis + explanation
        StructuredResponseFake::make()->withStructured([
            'intent_type' => 'general',
            'priority_filters' => [],
            'task_keywords' => [],
            'time_constraint' => null,
            'comparison_focus' => null,
        ])->withUsage(new Usage(5, 10)),
        StructuredResponseFake::make()->withStructured([
            'suggestion' => 'Focus on reading next.',
            'reason' => 'It will move you forward quickly.',
            'steps' => ['Skim headings', 'Read actively', 'Write 3 key points'],
        ])->withUsage(new Usage(5, 10)),
        // queued: context analysis + explanation
        StructuredResponseFake::make()->withStructured([
            'intent_type' => 'general',
            'priority_filters' => [],
            'task_keywords' => [],
            'time_constraint' => null,
            'comparison_focus' => null,
        ])->withUsage(new Usage(5, 10)),
        StructuredResponseFake::make()->withStructured([
            'suggestion' => 'Focus on reading next.',
            'reason' => 'It will move you forward quickly.',
            'steps' => ['Skim headings', 'Read actively', 'Write 3 key points'],
        ])->withUsage(new Usage(5, 10)),
    ]);

    $service = app(TaskAssistantService::class);
    $runner = app(TaskAssistantTaskChoiceRunner::class);

    $syncThread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $syncResult = $service->runTaskChoice($syncThread, 'Help me choose what to work on next.', $runner);
    $syncAssistant = $syncThread->messages()->where('role', 'assistant')->latest('id')->first();

    $queuedThread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $queuedUserMsg = $queuedThread->messages()->create([
        'role' => \App\Enums\MessageRole::User,
        'content' => 'Help me choose what to work on next.',
    ]);
    $queuedAssistantMsg = $queuedThread->messages()->create([
        'role' => \App\Enums\MessageRole::Assistant,
        'content' => '',
    ]);

    $service->processQueuedMessage($queuedThread, $queuedUserMsg->id, $queuedAssistantMsg->id, \App\Enums\TaskAssistantIntent::TaskPrioritization);

    $queuedAssistantMsg->refresh();

    expect($syncResult['valid'])->toBeTrue();
    expect($syncAssistant)->not->toBeNull();
    expect($queuedAssistantMsg->content)->toBe($syncAssistant->content);
    expect($queuedAssistantMsg->metadata['processed'] ?? false)->toBeTrue();
});

// Additional tests for intent-based tool gating and mutating flows
// live in dedicated unit tests to keep this feature file focused on
// high-level orchestration behavior.
