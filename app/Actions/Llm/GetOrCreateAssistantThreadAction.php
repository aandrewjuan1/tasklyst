<?php

namespace App\Actions\Llm;

use App\Models\AssistantThread;
use App\Models\User;
use App\Services\AssistantConversationService;

class GetOrCreateAssistantThreadAction
{
    public function __construct(
        private AssistantConversationService $conversationService
    ) {}

    public function execute(User $user, ?int $threadId = null): AssistantThread
    {
        return $this->conversationService->getOrCreateThread($user, $threadId);
    }
}
