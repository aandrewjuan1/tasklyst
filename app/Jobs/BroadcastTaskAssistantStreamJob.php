<?php

namespace App\Jobs;

use App\Models\TaskAssistantThread;
use App\Services\TaskAssistantService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
        $thread = TaskAssistantThread::query()->find($this->threadId);
        if (! $thread || $thread->user_id !== $this->userId) {
            return;
        }

        $service->broadcastStream($thread, $this->userMessageId, $this->assistantMessageId);
    }
}
