<?php

use App\Actions\Llm\CallLlmAction;
use App\DataTransferObjects\Llm\LlmRawResponseDto;
use App\Enums\ChatMessageRole;
use App\Events\LlmResponseReady;
use App\Jobs\ProcessLlmRequestJob;
use App\Models\ChatThread;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

test('persists an assistant chat message after successful job execution', function (): void {
    $user = User::factory()->create();
    $thread = ChatThread::query()->create([
        'user_id' => $user->id,
        'title' => 'Thread',
        'schema_version' => config('llm.schema_version'),
    ]);

    $this->mock(CallLlmAction::class)
        ->shouldReceive('__invoke')
        ->andReturn(new LlmRawResponseDto(json_encode([
            'schema_version' => config('llm.schema_version'),
            'intent' => 'general',
            'data' => [],
            'tool_call' => null,
            'message' => 'Here are your tasks.',
            'meta' => [
                'confidence' => 0.85,
            ],
        ]), 200));

    Event::fake([LlmResponseReady::class]);

    ProcessLlmRequestJob::dispatchSync(
        user: $user,
        thread: $thread,
        message: 'Show me my tasks',
        clientRequestId: (string) Str::uuid(),
        traceId: (string) Str::uuid(),
    );

    expect(
        $thread->messages()
            ->where('role', ChatMessageRole::Assistant->value)
            ->exists()
    )->toBeTrue();

    Event::assertDispatched(
        LlmResponseReady::class,
        fn (LlmResponseReady $event): bool => $event->userId === $user->id
            && $event->threadId === $thread->id,
    );
});

test('persists a safe error chat message when the job permanently fails', function (): void {
    $user = User::factory()->create();
    $thread = ChatThread::query()->create([
        'user_id' => $user->id,
        'title' => 'Thread',
        'schema_version' => config('llm.schema_version'),
    ]);

    $job = new ProcessLlmRequestJob(
        user: $user,
        thread: $thread,
        message: 'test',
        clientRequestId: (string) Str::uuid(),
        traceId: (string) Str::uuid(),
    );

    $job->failed(new RuntimeException('Ollama is down'));

    expect(
        $thread->messages()
            ->where('role', ChatMessageRole::Assistant->value)
            ->whereJsonContains('meta->error', true)
            ->exists()
    )->toBeTrue();
});
