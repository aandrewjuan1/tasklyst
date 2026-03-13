<?php

namespace App\Jobs;

use App\Enums\ChatMessageRole;
use App\Events\LlmResponseReady;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use App\Services\Llm\LlmChatService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessLlmRequestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout;

    public int $tries;

    public function __construct(
        public readonly User $user,
        public readonly ChatThread $thread,
        public readonly string $message,
        public readonly string $clientRequestId,
        public readonly string $traceId,
    ) {
        $this->timeout = (int) config('llm.queue.timeout');
        $this->tries = (int) config('llm.queue.tries');
        $this->onQueue(config('llm.queue.name'));
        $this->onConnection(config('llm.queue.connection'));
    }

    public function handle(LlmChatService $service): void
    {
        $service->handle(
            user: $this->user,
            threadId: (string) $this->thread->id,
            message: $this->message,
            traceId: $this->traceId,
        );

        event(new LlmResponseReady(
            userId: $this->user->id,
            threadId: $this->thread->id,
        ));
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel(config('llm.log.channel'))->critical('llm.job.permanent_failure', [
            'trace_id' => $this->traceId,
            'user_id' => $this->user->id,
            'thread_id' => $this->thread->id,
            'exception' => $exception->getMessage(),
        ]);

        ChatMessage::query()->create([
            'thread_id' => $this->thread->id,
            'role' => ChatMessageRole::Assistant,
            'content_text' => "Sorry, I couldn't process that request. Please try again.",
            'meta' => [
                'error' => true,
                'trace_id' => $this->traceId,
            ],
        ]);
    }
}
