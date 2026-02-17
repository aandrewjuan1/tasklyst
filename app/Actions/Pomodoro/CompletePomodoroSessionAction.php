<?php

namespace App\Actions\Pomodoro;

use App\Actions\FocusSession\CompleteFocusSessionAction;
use App\Enums\FocusSessionType;
use App\Models\FocusSession;
use Carbon\CarbonInterface;

class CompletePomodoroSessionAction
{
    public function __construct(
        private CompleteFocusSessionAction $completeFocusSessionAction,
        private GetNextPomodoroSessionTypeAction $getNextPomodoroSessionTypeAction,
        private GetOrCreatePomodoroSettingsAction $getOrCreatePomodoroSettingsAction
    ) {}

    /**
     * Complete a pomodoro session and determine what comes next.
     * Returns the completed session and information about the next session (if applicable).
     *
     * @param  FocusSession  $session  The session to complete
     * @param  CarbonInterface|string  $endedAt  When the session ended
     * @param  bool  $completed  Whether the session was completed (true) or abandoned (false)
     * @param  int  $pausedSeconds  Total paused seconds
     * @param  string|null  $markTaskStatus  Optional task status to set (to_do | doing | done)
     * @return array{session: FocusSession, next_session: array{type: FocusSessionType, sequence_number: int, duration_seconds: int, auto_start: bool}|null}
     */
    public function execute(
        FocusSession $session,
        CarbonInterface|string $endedAt,
        bool $completed,
        int $pausedSeconds = 0,
        ?string $markTaskStatus = null
    ): array {
        // Complete the session using the existing action
        $completedSession = $this->completeFocusSessionAction->execute(
            $session,
            $endedAt,
            $completed,
            $pausedSeconds,
            $markTaskStatus
        );

        $nextSession = null;

        // Only determine next session if this was a completed pomodoro session
        if ($completed && $this->isPomodoroSession($completedSession)) {
            $settings = $this->getOrCreatePomodoroSettingsAction->execute($completedSession->user);

            $nextSessionInfo = $this->getNextPomodoroSessionTypeAction->execute($completedSession, $settings);

            // Determine if auto-start should happen
            $autoStart = match ($nextSessionInfo['type']) {
                FocusSessionType::ShortBreak, FocusSessionType::LongBreak => $settings->auto_start_break,
                FocusSessionType::Work => $settings->auto_start_pomodoro,
            };

            $nextSession = [
                'type' => $nextSessionInfo['type'],
                'sequence_number' => $nextSessionInfo['sequence_number'],
                'duration_seconds' => $nextSessionInfo['duration_seconds'],
                'auto_start' => $autoStart,
            ];
        }

        return [
            'session' => $completedSession,
            'next_session' => $nextSession,
        ];
    }

    /**
     * Check if a session is part of a pomodoro cycle.
     * A pomodoro session is identified by having focus_mode_type in payload or being a work session
     * that could be part of a pomodoro cycle.
     */
    private function isPomodoroSession(FocusSession $session): bool
    {
        $payload = $session->payload ?? [];

        // Check if focus_mode_type is set to pomodoro
        if (isset($payload['focus_mode_type']) && $payload['focus_mode_type'] === 'pomodoro') {
            return true;
        }

        // If it's a break session, it's likely part of a pomodoro cycle
        if ($session->type === FocusSessionType::ShortBreak || $session->type === FocusSessionType::LongBreak) {
            return true;
        }

        return false;
    }
}
