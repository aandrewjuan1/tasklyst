<?php

namespace App\Actions\FocusSession;

use App\Models\FocusSession;
use App\Models\User;

class GetActiveFocusSessionAction
{
    public function execute(User $user): ?FocusSession
    {
        return FocusSession::query()
            ->forUser($user->id)
            ->inProgress()
            ->first();
    }
}
