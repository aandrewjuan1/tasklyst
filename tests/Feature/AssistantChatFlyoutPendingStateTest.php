<?php

use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\User;
use Livewire\Livewire;

it('restores pending assistant state on mount when last user message has active trace id and no assistant reply', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    /** @var AssistantThread $thread */
    $thread = AssistantThread::factory()->for($user)->create();

    /** @var AssistantMessage $firstUser */
    $firstUser = AssistantMessage::factory()
        ->for($thread)
        ->user()
        ->create();

    expect($firstUser->role)->toBe('user');

    $traceId = 'test-trace-id-123';

    /** @var AssistantMessage $pendingUser */
    $pendingUser = AssistantMessage::factory()
        ->for($thread)
        ->user()
        ->withMetadata([
            'llm_trace_id' => $traceId,
        ])
        ->create([
            'created_at' => now()->addMinute(),
        ]);

    expect($pendingUser->role)->toBe('user');

    $component = Livewire::test('assistant.chat-flyout', [
        'threadId' => $thread->id,
    ]);

    $component
        ->assertSet('pendingAssistantCount', 1)
        ->assertSet('currentTraceId', $traceId);
});
