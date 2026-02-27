<?php

use App\Models\AssistantThread;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('assistant.thread.{threadId}', function ($user, int $threadId): bool {
    return AssistantThread::query()
        ->forUser($user->id)
        ->whereKey($threadId)
        ->exists();
});
