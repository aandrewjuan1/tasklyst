<?php

namespace App\Console\Commands;

use App\Actions\CalendarFeed\SyncCalendarFeedAction;
use App\Enums\CalendarFeedSyncStatus;
use App\Models\CalendarFeed;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class SyncCalendarFeedsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'calendar:sync-feeds {--feed-id=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Brightspace calendar feeds for users';

    /**
     * Execute the console command.
     */
    public function __construct(
        private SyncCalendarFeedAction $syncCalendarFeedAction
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $feedId = $this->option('feed-id');

        if ($feedId !== null) {
            $feed = CalendarFeed::query()
                ->where('sync_enabled', true)
                ->find((int) $feedId);

            if (! $feed instanceof CalendarFeed) {
                $this->error('Calendar feed not found or not enabled.');

                return self::FAILURE;
            }

            $this->syncSingleFeed($feed);

            return self::SUCCESS;
        }

        /** @var Collection<int, CalendarFeed> $feeds */
        $feeds = CalendarFeed::query()
            ->syncEnabled()
            ->get();

        if ($feeds->isEmpty()) {
            $this->info('No enabled calendar feeds found.');

            return self::SUCCESS;
        }

        foreach ($feeds as $feed) {
            $this->syncSingleFeed($feed);
        }

        return self::SUCCESS;
    }

    private function syncSingleFeed(CalendarFeed $feed): void
    {
        $this->line(sprintf('Syncing feed #%d (%s)...', $feed->id, $feed->name ?? 'Brightspace'));

        try {
            $result = $this->syncCalendarFeedAction->execute($feed, notifyUserOnSuccess: false);

            if ($result->status === CalendarFeedSyncStatus::Completed) {
                $this->info($result->toastMessage(false));
            } else {
                $this->warn($result->toastMessage(false));
            }
        } catch (\Throwable $e) {
            Log::error('Failed to sync calendar feed from console command.', [
                'feed_id' => $feed->id,
                'user_id' => $feed->user_id,
                'exception' => $e,
            ]);

            $this->error(sprintf('Failed to sync feed #%d.', $feed->id));
        }
    }
}
