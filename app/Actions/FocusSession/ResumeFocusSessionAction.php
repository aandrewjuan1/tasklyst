<?php

namespace App\Actions\FocusSession;

use App\Models\FocusSession;

class ResumeFocusSessionAction
{
    public function execute(FocusSession $session): FocusSession
    {
        $session->flushPausedAt();

        return $session->fresh();
    }
}
