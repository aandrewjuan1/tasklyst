<?php

use App\Enums\MessageRole;
use App\Jobs\BroadcastTaskAssistantStreamJob;
use App\Models\TaskAssistantThread;
use App\Models\User;

test('failed job persists safe assistant error response', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Help me with my schedule',
    ]);

    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    $job = new BroadcastTaskAssistantStreamJob(
        threadId: $thread->id,
        userMessageId: $userMessage->id,
        assistantMessageId: $assistantMessage->id,
        userId: $user->id,
    );

    $job->failed(new RuntimeException('Simulated worker failure'));

    $assistantMessage->refresh();

    expect($assistantMessage->content)->toContain('temporary issue');
    expect(data_get($assistantMessage->metadata, 'structured.ok'))->toBeFalse();
    expect(data_get($assistantMessage->metadata, 'structured.flow'))->toBe('error');
    expect(data_get($assistantMessage->metadata, 'structured.data.error_code'))->toBe('assistant_processing_failed');
});
