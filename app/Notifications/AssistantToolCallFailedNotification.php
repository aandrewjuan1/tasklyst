<?php

namespace App\Notifications;

use App\Models\LlmToolCall;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AssistantToolCallFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $toolCallId,
        public readonly string $toolName,
        public readonly ?string $operationToken = null,
        public readonly ?int $threadId = null,
        public readonly ?int $messageId = null,
        public readonly ?string $error = null,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'assistant_tool_call_failed',
            'title' => __('Assistant action failed'),
            'message' => __('The assistant couldn’t complete an action (:tool).', ['tool' => $this->toolName]),
            'entity' => [
                'kind' => 'llm_tool_call',
                'id' => $this->toolCallId,
                'model' => LlmToolCall::class,
            ],
            'route' => 'workspace',
            'params' => [],
            'meta' => [
                'tool_name' => $this->toolName,
                'operation_token' => $this->operationToken,
                'thread_id' => $this->threadId,
                'message_id' => $this->messageId,
                'error' => $this->error,
            ],
        ];
    }
}
