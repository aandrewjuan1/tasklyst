<?php

use App\Enums\MessageRole;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread as TaskAssistantThreadModel;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantToolEventPersister;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

it('persists tool calls into assistant tool_calls and tool results into role=tool messages', function (): void {
    $user = User::factory()->create();

    /** @var TaskAssistantThread $thread */
    $thread = TaskAssistantThreadModel::factory()->create([
        'user_id' => $user->id,
    ]);

    $assistantMessage = TaskAssistantMessage::factory()->create([
        'thread_id' => $thread->id,
        'role' => MessageRole::Assistant,
        'content' => '',
        'tool_calls' => null,
        'metadata' => [],
    ]);

    $toolCall = new ToolCall(
        id: 'tc_1',
        name: 'list_tasks',
        arguments: [
            'projectId' => 123,
            'limit' => 2,
        ],
    );

    $toolResult = new ToolResult(
        toolCallId: 'tc_1',
        toolName: 'list_tasks',
        args: [
            'projectId' => 123,
        ],
        result: '{"ok":true,"tasks":[{"id":1,"title":"Example"}]}',
    );

    $persister = app(TaskAssistantToolEventPersister::class);
    $persister->persistToolCallsAndResults(
        assistantMessage: $assistantMessage,
        toolCalls: [$toolCall],
        toolResults: [$toolResult],
    );

    $assistantMessage->refresh();

    expect($assistantMessage->tool_calls)->toBeArray();
    expect(count($assistantMessage->tool_calls))->toBe(1);

    expect($assistantMessage->tool_calls[0]['id'])->toBe('tc_1');
    expect($assistantMessage->tool_calls[0]['name'])->toBe('list_tasks');
    expect($assistantMessage->tool_calls[0]['arguments'])->toEqual([
        'projectId' => 123,
        'limit' => 2,
    ]);

    $toolMessages = TaskAssistantMessage::query()
        ->where('thread_id', $thread->id)
        ->where('role', MessageRole::Tool)
        ->get();

    expect($toolMessages)->toHaveCount(1);

    $toolMessage = $toolMessages->first();
    $meta = $toolMessage->metadata;

    expect($meta['tool_call_id'])->toBe('tc_1');
    expect($meta['tool_name'])->toBe('list_tasks');
    expect($meta['args'])->toEqual([
        'projectId' => 123,
    ]);
});
