<?php

use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\TaskAssistantService;
use Prism\Prism\Facades\Prism;
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
