<?php

namespace App\Actions\FocusSession;

use App\Enums\ActivityLogAction as ActivityLogActionEnum;
use App\Enums\FocusSessionType;
use App\Enums\ReminderStatus;
use App\Enums\ReminderType;
use App\Enums\TaskStatus;
use App\Models\FocusSession;
use App\Models\Reminder;
use App\Models\Task;
use App\Services\ActivityLogRecorder;
use App\Services\Reminders\ReminderDispatcherService;
use App\Services\TaskService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class CompleteFocusSessionAction
{
    public function __construct(
        private ActivityLogRecorder $activityLogRecorder,
        private TaskService $taskService,
        private ReminderDispatcherService $reminderDispatcherService,
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
        $appTimezone = config('app.timezone');
        $endedAt = $endedAt instanceof CarbonInterface
            ? $endedAt->copy()->setTimezone($appTimezone)
            : Carbon::parse($endedAt)->setTimezone($appTimezone);

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
                    $task = $session->focusable;
                    $task->loadMissing('recurringTask');
                    $occurrenceDate = $session->payload['occurrence_date'] ?? null;
                    if ($task->recurringTask !== null && $occurrenceDate !== null && $occurrenceDate !== '') {
                        $this->taskService->updateRecurringOccurrenceStatus(
                            $task,
                            Carbon::parse($occurrenceDate),
                            $status
                        );
                    } else {
                        $this->taskService->updateTask($task, ['status' => $status->value]);
                    }
                }
            }

            Reminder::query()->create([
                'user_id' => $session->user_id,
                'remindable_type' => $session->getMorphClass(),
                'remindable_id' => $session->id,
                'type' => ReminderType::FocusSessionCompleted,
                'scheduled_at' => now(),
                'status' => ReminderStatus::Pending,
                'payload' => [
                    'focus_session_id' => $session->id,
                    'task_id' => $session->focusable instanceof Task ? $session->focusable->id : null,
                    'duration_seconds' => (int) $session->duration_seconds,
                ],
            ]);

            $this->reminderDispatcherService->queueProcessDueForRemindable($session);
        }

        return $session->fresh();
    }
}
