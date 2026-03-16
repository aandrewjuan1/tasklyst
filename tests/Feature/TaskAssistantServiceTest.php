<?php

use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\TaskAssistantService;
use App\Services\TaskAssistantTaskChoiceRunner;
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

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'chosen_task_id' => null,
                'chosen_task_title' => null,
                'summary' => 'Focus on reading today.',
                'reason' => 'You have upcoming reading deadlines.',
                'suggested_next_steps' => [
                    'Pick one reading task from your list.',
                    'Block 25–30 minutes on your calendar.',
                ],
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $service = app(TaskAssistantService::class);
    $runner = app(TaskAssistantTaskChoiceRunner::class);

    $result = $service->runTaskChoice($thread, 'Help me choose what to work on next.', $runner);

    expect($result['valid'])->toBeTrue();
    expect($result['data']['summary'])->toBe('Focus on reading today.');

    $thread->refresh();
    $assistantMessage = $thread->messages()
        ->where('role', 'assistant')
        ->latest('id')
        ->first();

    expect($assistantMessage)->not->toBeNull();
    expect($assistantMessage->metadata['task_choice']['summary'] ?? null)->toBe('Focus on reading today.');
});

test('runTaskChoice retries after invalid response then accepts valid one', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                // missing required fields to trigger validation error
                'chosen_task_id' => null,
            ])
            ->withUsage(new Usage(5, 10)),
        StructuredResponseFake::make()
            ->withStructured([
                'chosen_task_id' => null,
                'chosen_task_title' => null,
                'summary' => 'Second attempt summary.',
                'reason' => 'Second attempt reason.',
                'suggested_next_steps' => ['Step 1'],
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $service = app(TaskAssistantService::class);
    $runner = app(TaskAssistantTaskChoiceRunner::class);

    $result = $service->runTaskChoice($thread, 'Help me choose what to work on next.', $runner);

    expect($result['valid'])->toBeTrue();
    expect($result['data']['summary'])->toBe('Second attempt summary.');
});

test('runTaskChoice uses fallback when all attempts invalid', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured(['chosen_task_id' => 999])
            ->withUsage(new Usage(5, 10)),
        StructuredResponseFake::make()
            ->withStructured(['chosen_task_id' => 999])
            ->withUsage(new Usage(5, 10)),
        StructuredResponseFake::make()
            ->withStructured(['chosen_task_id' => 999])
            ->withUsage(new Usage(5, 10)),
    ]);

    $service = app(TaskAssistantService::class);
    $runner = app(TaskAssistantTaskChoiceRunner::class);

    $result = $service->runTaskChoice($thread, 'Help me choose what to work on next.', $runner);

    expect($result['valid'])->toBeTrue();
    expect($result['data']['summary'])->not->toBe('');
});

// Additional tests for intent-based tool gating and mutating flows
// live in dedicated unit tests to keep this feature file focused on
// high-level orchestration behavior.
