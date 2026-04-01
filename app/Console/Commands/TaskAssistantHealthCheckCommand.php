<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class TaskAssistantHealthCheckCommand extends Command
{
    protected $signature = 'task-assistant:health-check';

    protected $description = 'Check queue and broadcast prerequisites for task assistant streaming.';

    public function handle(): int
    {
        $queueName = (string) config('task-assistant.queue', 'task-assistant');
        $queueConnection = (string) config('queue.default', 'sync');

        $this->line('Task Assistant health check');
        $this->line('---------------------------');
        $this->line('Queue connection: '.$queueConnection);
        $this->line('Queue name: '.$queueName);
        $this->line('Broadcast driver: '.(string) config('broadcasting.default', 'null'));

        try {
            DB::connection()->getPdo();
            $this->info('Database: OK');
        } catch (\Throwable $e) {
            $this->error('Database: FAILED ('.$e->getMessage().')');

            return self::FAILURE;
        }

        try {
            $size = Queue::connection($queueConnection)->size($queueName);
            $this->info('Queue connectivity: OK');
            $this->line('Pending jobs on `'.$queueName.'`: '.(string) $size);
        } catch (\Throwable $e) {
            $this->error('Queue connectivity: FAILED ('.$e->getMessage().')');

            return self::FAILURE;
        }

        $this->line('Tip: run `php artisan queue:work --queue='.$queueName.',default` if jobs are not being consumed.');

        return self::SUCCESS;
    }
}
