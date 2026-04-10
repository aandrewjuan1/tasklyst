<?php

namespace App\Console\Commands;

use App\Services\Reminders\ReminderDispatcherService;
use Illuminate\Console\Command;

class DispatchDueRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reminders:dispatch-due {--limit=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch due reminders and create inbox notifications';

    /**
     * Execute the console command.
     */
    public function __construct(
        private ReminderDispatcherService $reminderDispatcherService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limitOpt = $this->option('limit');
        $limit = $limitOpt !== null ? (int) $limitOpt : (int) config('reminders.dispatch.default_limit', 200);
        $limit = max(1, $limit);

        $count = $this->reminderDispatcherService->dispatchDue($limit);

        $this->line('Dispatched reminders: '.(string) $count);

        return self::SUCCESS;
    }
}
