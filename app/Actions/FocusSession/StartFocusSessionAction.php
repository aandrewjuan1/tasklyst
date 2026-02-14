<?php

namespace App\Actions\FocusSession;

use App\Enums\FocusSessionType;
use App\Models\FocusSession;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class StartFocusSessionAction
{
    public function __construct(
        private GetActiveFocusSessionAction $getActiveFocusSessionAction,
        private AbandonFocusSessionAction $abandonFocusSessionAction
    ) {}

    /**
     * Start a focus session. Ensures at most one in-progress session per user by abandoning any current one.
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
        array $payload = []
    ): FocusSession {
        $active = $this->getActiveFocusSessionAction->execute($user);
        if ($active !== null) {
            $this->abandonFocusSessionAction->execute($active);
        }

        $startedAt = $startedAt instanceof CarbonInterface
            ? $startedAt
            : Carbon::parse($startedAt);

        return FocusSession::query()->create([
            'user_id' => $user->id,
            'focusable_type' => $task !== null ? $task->getMorphClass() : null,
            'focusable_id' => $task?->getKey(),
            'type' => $type,
            'sequence_number' => $sequenceNumber,
            'duration_seconds' => $durationSeconds,
            'completed' => false,
            'started_at' => $startedAt,
            'ended_at' => null,
            'paused_seconds' => 0,
            'payload' => $payload !== [] ? $payload : null,
        ]);
    }
}
