<?php

namespace App\Actions\Pomodoro;

use App\Models\FocusSession;
use App\Models\User;

class GetPomodoroSequenceNumberAction
{
    /**
     * Get the next pomodoro work session sequence number for the user today.
     * Counts completed work sessions today and returns the next sequence number.
     *
     * @return int The next sequence number (starts at 1)
     */
    public function execute(User $user): int
    {
        $completedWorkSessionsToday = FocusSession::query()
            ->forUser($user->id)
            ->work()
            ->completed()
            ->today()
            ->count();

        return $completedWorkSessionsToday + 1;
    }
}
