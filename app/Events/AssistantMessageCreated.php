<?php

namespace App\Events;

use App\Models\AssistantMessage;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssistantMessageCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public AssistantMessage $message,
        public User $user
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("assistant.thread.{$this->message->assistant_thread_id}"),
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'thread_id' => $this->message->assistant_thread_id,
            'role' => $this->message->role,
            'content' => $this->message->content,
            'metadata' => $this->message->metadata,
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
