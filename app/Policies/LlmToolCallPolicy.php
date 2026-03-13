<?php

namespace App\Policies;

use App\Models\LlmToolCall;
use App\Models\User;

class LlmToolCallPolicy
{
    public function view(User $user, LlmToolCall $toolCall): bool
    {
        return $user->id === $toolCall->user_id;
    }
}
