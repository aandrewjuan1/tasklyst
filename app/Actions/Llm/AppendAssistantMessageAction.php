<?php

namespace App\Actions\Llm;

use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Services\AssistantConversationService;

class AppendAssistantMessageAction
{
    public function __construct(
        private AssistantConversationService $conversationService
    ) {}

    public function execute(AssistantThread $thread, string $role, string $content, array $metadata = []): AssistantMessage
    {
        return $this->conversationService->appendMessage($thread, $role, $content, $metadata);
    }
}
