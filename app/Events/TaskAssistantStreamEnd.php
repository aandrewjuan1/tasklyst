<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskAssistantStreamEnd implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public int $userId,
        public int $assistantMessageId,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('task-assistant.user.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'stream_end';
    }

    /**
     * @return array{assistant_message_id: int}
     */
    public function broadcastWith(): array
    {
        return [
            'assistant_message_id' => $this->assistantMessageId,
        ];
    }
}
