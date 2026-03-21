<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskAssistantToolResult implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public int $userId,
        public string $toolCallId,
        public string $toolName,
        public string $result,
        public bool $success = true,
        public ?string $error = null,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('task-assistant.user.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'tool_result';
    }

    /**
     * @return array{
     *   tool_call_id: string,
     *   tool_name: string,
     *   success: bool,
     *   error: (string|null)
     * }
     */
    public function broadcastWith(): array
    {
        $error = $this->error;
        if ($error !== null && mb_strlen($error) > 600) {
            $error = mb_substr($error, 0, 600).'…(truncated)';
        }

        return [
            'tool_call_id' => $this->toolCallId,
            'tool_name' => $this->toolName,
            'success' => $this->success,
            'error' => $error,
        ];
    }
}
