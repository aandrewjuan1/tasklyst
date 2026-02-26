<?php

namespace App\Services;

use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\User;

class AssistantConversationService
{
    public function getOrCreateThread(User $user, ?int $threadId = null): AssistantThread
    {
        if ($threadId !== null) {
            $thread = AssistantThread::query()
                ->forUser($user->id)
                ->find($threadId);

            if ($thread !== null) {
                return $thread;
            }
        }

        return $user->assistantThreads()->create([
            'title' => null,
        ]);
    }

    public function getLatestThread(User $user): ?AssistantThread
    {
        return AssistantThread::query()
            ->forUser($user->id)
            ->recent()
            ->first();
    }

    public function appendMessage(AssistantThread $thread, string $role, string $content, array $metadata = []): AssistantMessage
    {
        $message = $thread->messages()->create([
            'role' => $role,
            'content' => $content,
            'metadata' => $metadata !== [] ? $metadata : null,
        ]);

        $thread->touch();

        return $message;
    }
}
