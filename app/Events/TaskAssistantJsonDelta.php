<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskAssistantJsonDelta implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public int $userId,
        public string $delta
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('task-assistant.user.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'json_delta';
    }

    /**
     * @return array{delta: string}
     */
    public function broadcastWith(): array
    {
        return [
            'delta' => $this->delta,
        ];
    }
}
