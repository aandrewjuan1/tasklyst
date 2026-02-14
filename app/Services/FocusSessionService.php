<?php

namespace App\Services;

use App\Actions\FocusSession\AbandonFocusSessionAction;
use App\Actions\FocusSession\CompleteFocusSessionAction;
use App\Actions\FocusSession\GetActiveFocusSessionAction;
use App\Actions\FocusSession\StartFocusSessionAction;
use App\Enums\FocusSessionType;
use App\Models\FocusSession;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class FocusSessionService
{
    public function __construct(
        private GetActiveFocusSessionAction $getActiveFocusSessionAction,
        private StartFocusSessionAction $startFocusSessionAction,
        private CompleteFocusSessionAction $completeFocusSessionAction,
        private AbandonFocusSessionAction $abandonFocusSessionAction
    ) {}

    public function getActiveSessionForUser(User $user): ?FocusSession
    {
        return $this->getActiveFocusSessionAction->execute($user);
    }

    public function startWorkSession(
        User $user,
        Task $task,
        CarbonInterface $startedAt,
        int $durationSeconds,
        bool $usedTaskDuration = false
    ): FocusSession {
        return $this->startFocusSessionAction->execute(
            $user,
            $task,
            FocusSessionType::Work,
            $durationSeconds,
            $startedAt,
            1,
            $usedTaskDuration ? ['used_task_duration' => true] : ['used_default_duration' => true]
        );
    }

    public function startBreakSession(
        User $user,
        FocusSessionType $breakType,
        CarbonInterface $startedAt,
        int $durationSeconds,
        int $sequenceNumber
    ): FocusSession {
        return $this->startFocusSessionAction->execute(
            $user,
            null,
            $breakType,
            $durationSeconds,
            $startedAt,
            $sequenceNumber,
            []
        );
    }

    public function completeSession(
        FocusSession $session,
        CarbonInterface $endedAt,
        bool $completed,
        int $pausedSeconds = 0,
        ?string $markTaskStatus = null
    ): FocusSession {
        return $this->completeFocusSessionAction->execute($session, $endedAt, $completed, $pausedSeconds, $markTaskStatus);
    }

    public function abandonSession(FocusSession $session): FocusSession
    {
        return $this->abandonFocusSessionAction->execute($session);
    }

    /**
     * @return Collection<int, FocusSession>
     */
    public function getSessionsForTask(Task $task, ?CarbonInterface $date = null): Collection
    {
        $query = FocusSession::query()->forTask($task);

        if ($date !== null) {
            $query->whereDate('started_at', $date);
        }

        return $query->orderByDesc('started_at')->get();
    }

    /**
     * @return Collection<int, FocusSession>
     */
    public function getSessionsForUserToday(User $user): Collection
    {
        return FocusSession::query()
            ->forUser($user->id)
            ->today()
            ->orderByDesc('started_at')
            ->get();
    }
}
