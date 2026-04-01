<?php

namespace App\Jobs;

use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use App\Services\LLM\TaskAssistant\TaskAssistantStreamingBroadcaster;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class BroadcastTaskAssistantStreamJob implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public function __construct(
        public int $threadId,
        public int $userMessageId,
        public int $assistantMessageId,
        public int $userId,
    ) {
        $this->tries = max(1, (int) config('task-assistant.retry.max_retries', 2) + 1);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [2, 10, 30];
    }

    public function retryUntil(): CarbonImmutable
    {
        return now()->addMinutes(3)->toImmutable();
    }

    public function handle(TaskAssistantService $service): void
    {
        $this->markAssistantPhase('processing');
        Log::debug('task-assistant.job.handle', [
            'layer' => 'queue_job',
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

            $this->persistFailureAndEndStream('assistant_thread_mismatch');

            return;
        }

        if ($this->isCancellationRequested()) {
            Log::debug('task-assistant.job.cancelled_before_start', [
                'layer' => 'queue_job',
                'thread_id' => $this->threadId,
                'assistant_message_id' => $this->assistantMessageId,
                'user_id' => $this->userId,
            ]);

            $this->markAsCancelled($thread);
            broadcast(new \App\Events\TaskAssistantStreamEnd($this->userId, $this->assistantMessageId));

            return;
        }

        Log::debug('task-assistant.job.broadcastStream.call', [
            'layer' => 'queue_job',
            'thread_id' => $this->threadId,
            'user_message_id' => $this->userMessageId,
            'assistant_message_id' => $this->assistantMessageId,
        ]);

        $service->processQueuedMessage($thread, $this->userMessageId, $this->assistantMessageId);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('task-assistant.job.failed', [
            'layer' => 'queue_job',
            'thread_id' => $this->threadId,
            'user_message_id' => $this->userMessageId,
            'assistant_message_id' => $this->assistantMessageId,
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);

        $this->persistFailureAndEndStream('assistant_processing_failed');
    }

    private function persistFailureAndEndStream(string $errorCode): void
    {
        $assistantMessage = TaskAssistantMessage::query()
            ->where('thread_id', $this->threadId)
            ->where('id', $this->assistantMessageId)
            ->where('role', \App\Enums\MessageRole::Assistant)
            ->first();

        if (! $assistantMessage) {
            return;
        }

        Log::warning('task-assistant.broadcast', [
            'layer' => 'broadcast',
            'stage' => 'failure_envelope',
            'thread_id' => $this->threadId,
            'assistant_message_id' => $this->assistantMessageId,
            'user_id' => $this->userId,
            'error_code' => $errorCode,
        ]);

        $assistantMessage->update([
            'content' => 'I ran into a temporary issue while preparing your response. Please try again.',
        ]);
        $this->markAssistantPhase('failed', ['error_code' => $errorCode]);

        app(TaskAssistantStreamingBroadcaster::class)->streamFinalAssistantJson(
            userId: $this->userId,
            assistantMessage: $assistantMessage,
            envelope: [
                'type' => 'task_assistant',
                'ok' => false,
                'flow' => 'error',
                'data' => [
                    'message' => (string) $assistantMessage->content,
                    'error_code' => $errorCode,
                ],
                'meta' => [
                    'thread_id' => $this->threadId,
                    'assistant_message_id' => $this->assistantMessageId,
                ],
            ],
        );
    }

    private function isCancellationRequested(): bool
    {
        $assistantMessage = TaskAssistantMessage::query()
            ->where('thread_id', $this->threadId)
            ->where('id', $this->assistantMessageId)
            ->where('role', \App\Enums\MessageRole::Assistant)
            ->first();

        if (! $assistantMessage) {
            return false;
        }

        return data_get($assistantMessage->metadata, 'stream.status') === 'stopped';
    }

    private function markAsCancelled(TaskAssistantThread $thread): void
    {
        $assistantMessage = TaskAssistantMessage::query()
            ->where('thread_id', $thread->id)
            ->where('id', $this->assistantMessageId)
            ->where('role', \App\Enums\MessageRole::Assistant)
            ->first();

        if ($assistantMessage) {
            $messageMetadata = is_array($assistantMessage->metadata ?? null) ? $assistantMessage->metadata : [];
            data_set($messageMetadata, 'stream.status', 'stopped');
            data_set($messageMetadata, 'stream.stopped_at', now()->toIso8601String());
            $assistantMessage->update([
                'content' => '',
                'metadata' => $messageMetadata,
            ]);
            $this->markAssistantPhase('cancelled');
        }

        $metadata = is_array($thread->metadata ?? null) ? $thread->metadata : [];
        data_set($metadata, 'stream.processing', null);
        data_set($metadata, 'stream.last_completed_at', now()->toIso8601String());
        $thread->update(['metadata' => $metadata]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function markAssistantPhase(string $phase, array $extra = []): void
    {
        $assistantMessage = TaskAssistantMessage::query()
            ->where('thread_id', $this->threadId)
            ->where('id', $this->assistantMessageId)
            ->where('role', \App\Enums\MessageRole::Assistant)
            ->first();

        if (! $assistantMessage) {
            return;
        }

        $metadata = is_array($assistantMessage->metadata ?? null) ? $assistantMessage->metadata : [];
        data_set($metadata, 'stream.phase', $phase);
        data_set($metadata, 'stream.phase_at', now()->toIso8601String());
        foreach ($extra as $key => $value) {
            data_set($metadata, 'stream.'.$key, $value);
        }
        $assistantMessage->update(['metadata' => $metadata]);
    }
}
