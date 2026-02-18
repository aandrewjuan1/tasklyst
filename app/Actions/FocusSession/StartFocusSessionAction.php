<?php

namespace App\Actions\FocusSession;

use App\Enums\FocusModeType;
use App\Enums\FocusSessionType;
use App\Enums\TaskStatus;
use App\Models\FocusSession;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class StartFocusSessionAction
{
    public function __construct(
        private GetActiveFocusSessionAction $getActiveFocusSessionAction,
        private AbandonFocusSessionAction $abandonFocusSessionAction,
        private TaskService $taskService
    ) {}

    /**
     * Start a focus session. Ensures at most one in-progress session per user by abandoning any current one.
     * When starting a work session for a task that is to_do, updates the task status to doing in the same flow.
     * For recurring tasks, pass occurrenceDate to create/update a TaskInstance for that date instead of the base task.
     *
     * @param  array<string, mixed>  $payload  Optional payload (e.g. used_task_duration, used_default_duration).
     */
    public function execute(
        User $user,
        ?Task $task,
        FocusSessionType $type,
        int $durationSeconds,
        CarbonInterface|string $startedAt,
        int $sequenceNumber = 1,
        array $payload = [],
        ?string $occurrenceDate = null
    ): FocusSession {
        $active = $this->getActiveFocusSessionAction->execute($user);
        if ($active !== null) {
            $this->abandonFocusSessionAction->execute($active);
        }

        $startedAt = $startedAt instanceof CarbonInterface
            ? $startedAt
            : Carbon::parse($startedAt);

        $occurrenceDateForPayload = null;

        if ($task !== null && $type === FocusSessionType::Work) {
            $task->loadMissing('recurringTask');
            $recurringTask = $task->recurringTask;
            if ($recurringTask !== null) {
                $date = ($occurrenceDate !== null && $occurrenceDate !== '')
                    ? Carbon::parse($occurrenceDate)
                    : $startedAt->copy()->startOfDay();
                $occurrenceDateForPayload = $date->format('Y-m-d');
                $effectiveStatus = $this->taskService->getEffectiveStatusForDateResolved($task, $date);
                if ($effectiveStatus === TaskStatus::ToDo) {
                    $this->taskService->updateRecurringOccurrenceStatus($task, $date, TaskStatus::Doing);
                }
            } elseif ($task->status === TaskStatus::ToDo) {
                $task->update(['status' => TaskStatus::Doing]);
            }
        }

        $sessionPayload = $payload;
        if ($occurrenceDateForPayload !== null) {
            $sessionPayload['occurrence_date'] = $occurrenceDateForPayload;
        } elseif ($occurrenceDate !== null && $occurrenceDate !== '') {
            $sessionPayload['occurrence_date'] = $occurrenceDate;
        }

        $focusModeType = FocusModeType::fromClient($sessionPayload['focus_mode_type'] ?? null);

        return FocusSession::query()->create([
            'user_id' => $user->id,
            'focusable_type' => $task !== null ? $task->getMorphClass() : null,
            'focusable_id' => $task?->getKey(),
            'type' => $type,
            'focus_mode_type' => $focusModeType,
            'sequence_number' => $sequenceNumber,
            'duration_seconds' => $durationSeconds,
            'completed' => false,
            'started_at' => $startedAt,
            'ended_at' => null,
            'paused_seconds' => 0,
            'payload' => $sessionPayload !== [] ? $sessionPayload : null,
        ]);
    }
}
