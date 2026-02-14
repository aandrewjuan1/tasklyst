<?php

namespace App\Actions\FocusSession;

use App\Models\FocusSession;
use Carbon\Carbon;

class AbandonFocusSessionAction
{
    public function execute(FocusSession $session, int $pausedSeconds = 0): FocusSession
    {
        $session->flushPausedAt();

        $finalPausedSeconds = max($session->paused_seconds, $pausedSeconds);

        $session->update([
            'ended_at' => Carbon::now(),
            'completed' => false,
            'paused_seconds' => $finalPausedSeconds,
        ]);

        return $session->fresh();
    }
}
