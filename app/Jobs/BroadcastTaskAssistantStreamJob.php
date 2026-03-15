<?php

namespace App\Jobs;

use App\Models\TaskAssistantThread;
use App\Services\TaskAssistantService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class BroadcastTaskAssistantStreamJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $threadId,
        public int $userMessageId,
        public int $assistantMessageId,
        public int $userId
    ) {}

    public function handle(TaskAssistantService $service): void
    {
        Log::info('task-assistant.job.handle', [
            'thread_id' => $this->threadId,
            'user_message_id' => $this->userMessageId,
            'assistant_message_id' => $this->assistantMessageId,
            'user_id' => $this->userId,
        ]);

        $thread = TaskAssistantThread::query()->find($this->threadId);
        if (! $thread || $thread->user_id !== $this->userId) {
            Log::warning('task-assistant.job.thread_mismatch', [
                'thread_id' => $this->threadId,
                'user_id' => $this->userId,
                'found_thread_user_id' => $thread?->user_id,
            ]);

            return;
        }

        Log::info('task-assistant.job.broadcastStream.call', [
            'thread_id' => $this->threadId,
            'user_message_id' => $this->userMessageId,
            'assistant_message_id' => $this->assistantMessageId,
        ]);

        $service->broadcastStream($thread, $this->userMessageId, $this->assistantMessageId);
    }
}
