<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskAssistantToolCall implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public int $userId,
        public string $toolCallId,
        public string $toolName,
        public array $arguments = [],
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('task-assistant.user.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'tool_call';
    }

    /**
     * @return array{tool_call_id: string, tool_name: string}
     */
    public function broadcastWith(): array
    {
        return [
            'tool_call_id' => $this->toolCallId,
            'tool_name' => $this->toolName,
        ];
    }
}
