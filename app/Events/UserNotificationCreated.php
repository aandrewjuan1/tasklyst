<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserNotificationCreated implements ShouldBroadcast
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public int $userId,
        public int $unreadCount
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'notification_created';
    }

    /**
     * @return array{unread_count: int}
     */
    public function broadcastWith(): array
    {
        return [
            'unread_count' => $this->unreadCount,
        ];
    }
}
