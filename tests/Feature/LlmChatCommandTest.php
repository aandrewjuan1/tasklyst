<?php

use App\Events\LlmResponseReady;
use App\Models\ChatThread;
use App\Models\User;
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
