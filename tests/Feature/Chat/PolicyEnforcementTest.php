<?php

use App\Actions\Llm\CallLlmAction;
use App\DataTransferObjects\Llm\LlmRawResponseDto;
use App\Models\ChatThread;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;

test('rejects tool execution when the proposed task belongs to a different user', function (): void {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();

    $task = Task::factory()->create([
        'user_id' => $owner->id,
    ]);

    $thread = ChatThread::query()->create([
        'user_id' => $attacker->id,
        'title' => 'Thread',
        'schema_version' => config('llm.schema_version'),
    ]);

    $this->mock(CallLlmAction::class)
        ->shouldReceive('__invoke')
        ->andReturn(new LlmRawResponseDto(json_encode([
            'schema_version' => config('llm.schema_version'),
            'intent' => 'update',
            'data' => [
                'id' => "task_{$task->id}",
                'fields' => [
                    'title' => 'hacked',
                ],
            ],
            'tool_call' => [
                'tool' => 'update_task',
                'args' => [
                    'id' => "task_{$task->id}",
                    'fields' => [
                        'title' => 'hacked',
                    ],
                ],
                'client_request_id' => 'req-'.Str::uuid(),
            ],
            'message' => 'Updated.',
            'meta' => [
                'confidence' => 0.9,
            ],
        ]), 100));

    $this->actingAs($attacker)
        ->postJson("/chat/threads/{$thread->id}/messages", [
            'message' => 'Update task '.$task->id,
            'client_request_id' => (string) Str::uuid(),
        ]);

    expect($task->fresh()->title)->not->toBe('hacked');
});

