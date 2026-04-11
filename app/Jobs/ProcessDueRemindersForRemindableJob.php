<?php

namespace App\Jobs;

use App\Services\Reminders\ReminderDispatcherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessDueRemindersForRemindableJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $remindableType,
        public int $remindableId,
    ) {}

    public function handle(ReminderDispatcherService $reminderDispatcherService): void
    {
        $reminderDispatcherService->dispatchDueForRemindable(
            $this->remindableType,
            $this->remindableId,
            now(),
        );
    }
}
