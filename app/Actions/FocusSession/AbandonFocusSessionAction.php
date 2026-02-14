<?php

namespace App\Actions\FocusSession;

use App\Models\FocusSession;
use Carbon\Carbon;

class AbandonFocusSessionAction
{
    public function execute(FocusSession $session): FocusSession
    {
        $session->update([
            'ended_at' => Carbon::now(),
            'completed' => false,
        ]);

        return $session->fresh();
    }
}
