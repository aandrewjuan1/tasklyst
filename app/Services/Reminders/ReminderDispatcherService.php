<?php

namespace App\Services\Reminders;

use App\Actions\Reminders\ProcessDueReminderByIdAction;
use App\Jobs\ProcessDueRemindersForRemindableJob;
use App\Models\Reminder;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class ReminderDispatcherService
{
    public function __construct(
        private ProcessDueReminderByIdAction $processDueReminderById,
    ) {}

    /**
     * Dispatch due reminders and return count processed.
     */
    public function dispatchDue(int $limit = 200, ?CarbonInterface $now = null): int
    {
        $now ??= now();
        $limit = max(1, $limit);

        $candidates = Reminder::query()
            ->due($now)
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->get();

        $dispatched = 0;

        foreach ($candidates as $reminder) {
            $success = $this->processDueReminderById->execute((int) $reminder->id, $now);
            if ($success) {
                $dispatched++;
            }
        }

        return $dispatched;
    }

    /**
     * Process pending due reminders for a single remindable (task, event, invitation, etc.).
     * Used by the scheduled command and by {@see queueProcessDueForRemindable()} after sync.
     *
     * @return int Number of reminders successfully processed (notification sent).
     */
    public function dispatchDueForRemindable(string $remindableType, int $remindableId, ?CarbonInterface $now = null): int
    {
        $now ??= now();
        $limit = max(1, (int) config('reminders.dispatch.per_remindable_limit', 25));

        $ids = Reminder::query()
            ->due($now)
            ->where('remindable_type', $remindableType)
            ->where('remindable_id', $remindableId)
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->pluck('id');

        $dispatched = 0;

        foreach ($ids as $reminderId) {
            $success = $this->processDueReminderById->execute((int) $reminderId, $now);
            if ($success) {
                $dispatched++;
            }
        }

        return $dispatched;
    }

    /**
     * Queue a post-commit pass over due reminders for this model, so inbox updates are not delayed until the next scheduler tick.
     *
     * Uses {@see DB::afterCommit()} so work runs only after any open transaction (e.g. TaskService save) has committed.
     * {@see Bus::dispatchSync()} runs the handler immediately (bounded by per_remindable_limit config)
     * so results are consistent and the minute scheduler is only a safety net.
     */
    public function queueProcessDueForRemindable(Model $remindable): void
    {
        $key = $remindable->getKey();
        if ($key === null) {
            return;
        }

        $remindableType = $remindable->getMorphClass();
        $remindableId = (int) $key;

        DB::afterCommit(function () use ($remindableType, $remindableId): void {
            Bus::dispatchSync(new ProcessDueRemindersForRemindableJob($remindableType, $remindableId));
        });
    }
}
