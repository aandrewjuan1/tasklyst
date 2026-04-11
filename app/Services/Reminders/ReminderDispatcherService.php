<?php

namespace App\Services\Reminders;

use App\Actions\Reminders\ProcessDueReminderByIdAction;
use App\Models\Reminder;
use Carbon\CarbonInterface;

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
}
