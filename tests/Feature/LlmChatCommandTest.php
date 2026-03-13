<?php

use App\DataTransferObjects\Ui\RecommendationDisplayDto;
use App\Events\LlmResponseReady;
use App\Models\ChatThread;
use App\Models\User;
use App\Services\Llm\LlmChatService;
use Illuminate\Support\Facades\Event;

test('llm chat command dispatches LlmResponseReady event', function (): void {
    $user = User::factory()->create();
    $thread = ChatThread::query()->create([
        'user_id' => $user->id,
        'title' => 'CLI Test Thread',
        'schema_version' => config('llm.schema_version'),
    ]);

    Event::fake([LlmResponseReady::class]);

    $this->artisan('llm:chat', [
        '--user-id' => $user->id,
        '--thread-id' => $thread->id,
    ])
        ->expectsQuestion('You', 'Hello')
        ->expectsOutputToContain('Assistant:')
        ->expectsOutputToContain('trace_id:')
        ->expectsQuestion('You', ':q')
        ->assertExitCode(0);

    Event::assertDispatched(
        LlmResponseReady::class,
        fn (LlmResponseReady $event): bool => $event->userId === $user->id
            && $event->threadId === $thread->id,
    );
});

test('llm chat command prints a helpful assistant response with reason and next step', function (): void {
    $user = User::factory()->create();
    $thread = ChatThread::query()->create([
        'user_id' => $user->id,
        'title' => 'CLI Test Thread',
        'schema_version' => config('llm.schema_version'),
    ]);

    $this->mock(LlmChatService::class)
        ->shouldReceive('__invoke')
        ->shouldReceive('handle')
        ->andReturn(new RecommendationDisplayDto(
            primaryMessage: 'Start with your Physics assignment because it is due the soonest and carries high weight. Next, work on the ITCS 101 – Midterm Project Checkpoint so you stay ahead on your project.',
            isError: false,
            traceId: 'trace-test-1',
        ));

    Event::fake([LlmResponseReady::class]);

    $this->artisan('llm:chat', [
        '--user-id' => $user->id,
        '--thread-id' => $thread->id,
    ])
        ->expectsQuestion('You', 'in my tasks what should i do first?')
        ->expectsOutputToContain('Physics assignment')
        ->expectsQuestion('You', ':q')
        ->assertExitCode(0);

    Event::assertDispatched(
        LlmResponseReady::class,
        fn (LlmResponseReady $event): bool => $event->userId === $user->id
            && $event->threadId === $thread->id,
    );
});
