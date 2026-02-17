<?php

namespace App\Actions\Pomodoro;

use App\Enums\FocusSessionType;
use App\Models\FocusSession;
use App\Models\PomodoroSetting;
use App\Models\User;

class GetNextPomodoroSessionTypeAction
{
    public function __construct(
        private GetPomodoroSequenceNumberAction $getPomodoroSequenceNumberAction
    ) {}

    /**
     * Determine the next pomodoro session type and sequence number based on the current session.
     *
     * @param  FocusSession  $currentSession  The session that just completed
     * @param  PomodoroSetting  $settings  User's pomodoro settings
     * @return array{type: FocusSessionType, sequence_number: int, duration_seconds: int}
     */
    public function execute(FocusSession $currentSession, PomodoroSetting $settings): array
    {
        $currentType = $currentSession->type;
        $currentSequence = $currentSession->sequence_number;

        // After a work session, determine break type
        if ($currentType === FocusSessionType::Work) {
            // Check if we should take a long break
            $shouldTakeLongBreak = ($currentSequence % $settings->long_break_after_pomodoros) === 0;

            $breakType = $shouldTakeLongBreak
                ? FocusSessionType::LongBreak
                : FocusSessionType::ShortBreak;

            $durationSeconds = $shouldTakeLongBreak
                ? $settings->long_break_minutes * 60
                : $settings->short_break_minutes * 60;

            return [
                'type' => $breakType,
                'sequence_number' => $currentSequence, // Break uses same sequence as the work session
                'duration_seconds' => $durationSeconds,
            ];
        }

        // After a break (short or long), start next work session
        $nextWorkSequence = $this->getPomodoroSequenceNumberAction->execute($currentSession->user);

        return [
            'type' => FocusSessionType::Work,
            'sequence_number' => $nextWorkSequence,
            'duration_seconds' => $settings->work_duration_minutes * 60,
        ];
    }
}
