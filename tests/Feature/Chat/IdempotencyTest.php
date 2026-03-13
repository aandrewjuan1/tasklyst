<?php

use App\DataTransferObjects\Llm\ToolCallDto;
use App\Models\ChatThread;
use App\Models\LlmToolCall;
use App\Models\Task;
use App\Models\User;
use App\Services\Llm\ToolExecutorService;
use Illuminate\Support\Str;

test('returns cached result and does not create a duplicate task when client_request_id is replayed', function (): void {
    $user = User::factory()->create();

    $thread = ChatThread::query()->create([
        'user_id' => $user->id,
        'title' => 'Thread',
        'schema_version' => config('llm.schema_version'),
    ]);

    $clientRequestId = 'req-'.Str::uuid();

    $toolCall = new ToolCallDto(
        tool: 'create_task',
        args: [
            'title' => 'Idempotency test task',
            'thread_id' => $thread->id,
        ],
        clientRequestId: $clientRequestId,
    );

    $service = app(ToolExecutorService::class);

    $result1 = $service->execute($toolCall, $user);
    expect($result1->success)->toBeTrue();

    $taskCountAfterFirst = Task::query()
        ->where('user_id', $user->id)
        ->count();

    $result2 = $service->execute($toolCall, $user);
    expect($result2->success)->toBeTrue();

    $taskCountAfterSecond = Task::query()
        ->where('user_id', $user->id)
        ->count();

    expect($taskCountAfterSecond)->toBe($taskCountAfterFirst)
        ->and(
            LlmToolCall::query()
                ->where('client_request_id', $clientRequestId)
                ->count()
        )->toBe(1);
});

