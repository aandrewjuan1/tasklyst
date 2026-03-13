<?php

namespace App\Policies;

use App\Models\ChatThread;
use App\Models\User;

class ChatThreadPolicy
{
    public function view(User $user, ChatThread $thread): bool
    {
        return $thread->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ChatThread $thread): bool
    {
        return $thread->user_id === $user->id;
    }

    public function delete(User $user, ChatThread $thread): bool
    {
        return $thread->user_id === $user->id;
    }

    public function sendMessage(User $user, ChatThread $thread): bool
    {
        return $thread->user_id === $user->id && $thread->deleted_at === null;
    }
}
