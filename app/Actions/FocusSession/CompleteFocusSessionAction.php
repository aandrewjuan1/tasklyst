<?php

namespace App\Actions\FocusSession;

use App\Enums\ActivityLogAction as ActivityLogActionEnum;
use App\Enums\FocusSessionType;
use App\Enums\TaskStatus;
use App\Models\FocusSession;
use App\Models\Task;
use App\Services\ActivityLogRecorder;
use App\Services\TaskService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class CompleteFocusSessionAction
{
    public function __construct(
        private ActivityLogRecorder $activityLogRecorder,
        private TaskService $taskService
    ) {}

    /**
     * Complete or abandon a focus session (set ended_at, completed, paused_seconds).
     * When completed=true, records FocusSessionCompleted activity log.
     * When completed work session with a task and markTaskStatus is set, updates the task's status (behaviours §5.3).
     *
     * @param  string|null  $markTaskStatus  Optional: to_do | doing | done
     */
    public function execute(
        FocusSession $session,
        CarbonInterface|string $endedAt,
        bool $completed,
        int $pausedSeconds = 0,
        ?string $markTaskStatus = null
    ): FocusSession {
        $endedAt = $endedAt instanceof CarbonInterface
            ? $endedAt
            : Carbon::parse($endedAt);

        $session->flushPausedAt();

        $finalPausedSeconds = max($session->paused_seconds, $pausedSeconds);

        $session->update([
            'ended_at' => $endedAt,
            'completed' => $completed,
            'paused_seconds' => $finalPausedSeconds,
        ]);

        if ($completed && $session->type === FocusSessionType::Work && $session->focusable !== null) {
            $this->activityLogRecorder->record(
                $session->focusable,
                $session->user,
                ActivityLogActionEnum::FocusSessionCompleted,
                [
                    'focus_session_id' => $session->id,
                    'duration_seconds' => $session->duration_seconds,
                ]
            );

            if ($markTaskStatus !== null && $markTaskStatus !== '' && $session->focusable instanceof Task) {
                $status = TaskStatus::tryFrom($markTaskStatus);
                if ($status !== null) {
                    $this->taskService->updateTask($session->focusable, ['status' => $status->value]);
                }
            }
        }

        return $session->fresh();
    }
}
