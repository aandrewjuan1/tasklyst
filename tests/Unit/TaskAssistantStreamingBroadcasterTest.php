<?php

use App\Enums\MessageRole;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantStreamingBroadcaster;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;

test('streaming broadcaster tolerates broadcast transport failures', function (): void {
    $user = User::factory()->create();
    assert($user instanceof User);

    $thread = TaskAssistantThread::factory()->create([
        'user_id' => $user->id,
    ]);

    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Final assistant response.',
        'metadata' => [],
    ]);

    $failingBroadcaster = \Mockery::mock(BroadcastFactory::class);
    $failingBroadcaster
        ->shouldReceive('event')
        ->atLeast()
        ->once()
        ->andThrow(new RuntimeException('broadcast transport unavailable'));

    app()->instance(BroadcastFactory::class, $failingBroadcaster);

    app(TaskAssistantStreamingBroadcaster::class)->streamFinalAssistantJson(
        userId: $user->id,
        assistantMessage: $assistantMessage,
        envelope: [
            'type' => 'task_assistant',
            'ok' => true,
            'flow' => 'general_guidance',
            'data' => ['message' => 'Final assistant response.'],
            'meta' => [
                'thread_id' => $thread->id,
                'assistant_message_id' => $assistantMessage->id,
            ],
        ],
        chunkSize: 12,
    );

    $assistantMessage->refresh();
    expect(data_get($assistantMessage->metadata, 'streamed'))->toBeTrue();
    expect(data_get($assistantMessage->metadata, 'structured.flow'))->toBe('general_guidance');
});
