<?php

namespace App\Actions\FocusSession;

use App\Models\FocusSession;
use Carbon\Carbon;

class PauseFocusSessionAction
{
    public function execute(FocusSession $session): FocusSession
    {
        if ($session->paused_at !== null) {
            return $session->fresh();
        }

        $session->update(['paused_at' => Carbon::now()]);

        return $session->fresh();
    }
}
