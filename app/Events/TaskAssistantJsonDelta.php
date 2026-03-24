<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskAssistantJsonDelta implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public int $userId,
        public int $assistantMessageId,
        public string $delta
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('task-assistant.user.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'json_delta';
    }

    /**
     * @return array{assistant_message_id: int, delta: string}
     */
    public function broadcastWith(): array
    {
        return [
            'assistant_message_id' => $this->assistantMessageId,
            'delta' => $this->delta,
        ];
    }
}
