<?php

use App\Enums\MessageRole;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
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
                'reasoning' => 'These tasks matched the filters and score highest by urgency.',
                'suggested_guidance' => 'I suggest starting with one task from this list so you do not feel overwhelmed. If you tell me your focus, I can narrow the list further or help you plan what to do next.',
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
                'reasoning' => 'These tasks matched the filters and score highest by urgency.',
                'suggested_guidance' => 'I suggest starting with the first task that feels most doable today. If you want, tell me whether you prefer school work or chores and I will refine the list.',
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

test('chat flow persists prism tool calls on the assistant message', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    $toolCall = new ToolCall('call-1', 'list_tasks', []);
    $toolResult = new ToolResult('call-1', 'list_tasks', [], ['ok' => true, 'tasks' => []]);

    Prism::fake([
        TextResponseFake::make()
            ->withText('Here are your tasks.')
            ->withFinishReason(FinishReason::Stop)
            ->withToolCalls([$toolCall])
            ->withToolResults([$toolResult])
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

    $assistantMessage->refresh();

    expect($assistantMessage->tool_calls)->toBeArray();
    expect($assistantMessage->tool_calls)->not->toBeEmpty();
    expect($assistantMessage->tool_calls[0]['name'] ?? null)->toBe('list_tasks');
});

test('prioritize flow replaces last_listing with prioritize results for multiturn state', function (): void {
    config(['task-assistant.intent.use_llm' => false]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'reasoning' => 'These tasks matched the filters and score highest by urgency.',
                'suggested_guidance' => 'I suggest starting with one task from the list so you do not get overwhelmed. If you want, I can narrow the list with a tighter filter or help you plan what to do next.',
            ])
            ->withUsage(new Usage(5, 10)),
        StructuredResponseFake::make()
            ->withStructured([
                'reasoning' => 'Ranked by urgency.',
                'suggested_guidance' => 'I recommend picking one task to open first so you can focus without feeling overwhelmed. Tell me if you want a tighter filter or help prioritizing.',
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
