<?php

namespace App\Services;

use App\Enums\TaskPriority;
use App\Enums\TaskSourceType;
use App\Enums\TaskStatus;
use App\Models\CalendarFeed;
use App\Models\Task;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CalendarFeedSyncService
{
    public function __construct(
        private IcsParserService $icsParserService
    ) {}

    public function sync(CalendarFeed $feed): void
    {
        if (! $feed->sync_enabled) {
            return;
        }

        try {
            /** @var Response $response */
            $response = Http::timeout(15)->get($feed->feed_url);
            if (! $response->successful()) {
                return;
            }

            $body = $response->body();
            if ($body === null || trim($body) === '') {
                return;
            }

            $events = $this->icsParserService->parse($body);
        } catch (\Throwable $e) {
            Log::error('Failed to fetch or parse calendar feed.', [
                'feed_id' => $feed->id,
                'user_id' => $feed->user_id,
                'exception' => $e,
            ]);

            return;
        }
        if ($events === []) {
            $feed->update(['last_synced_at' => now()]);

            return;
        }

        DB::transaction(function () use ($feed, $events): void {
            foreach ($events as $event) {
                if (! isset($event['uid'])) {
                    continue;
                }

                $uid = (string) $event['uid'];
                $summary = $event['summary'] ?? null;
                $description = $event['description'] ?? null;

                Task::query()->updateOrCreate(
                    [
                        'user_id' => $feed->user_id,
                        'source_type' => TaskSourceType::Brightspace,
                        'source_id' => $uid,
                    ],
                    [
                        'title' => $summary ?: __('Untitled'),
                        'description' => $description,
                        'start_datetime' => $event['dtstart'] ?? null,
                        'end_datetime' => $event['dtend'] ?? null,
                        'calendar_feed_id' => $feed->id,
                        'status' => TaskStatus::ToDo,
                        'priority' => TaskPriority::Medium,
                        'project_id' => null,
                        'event_id' => null,
                    ]
                );
            }

            $feed->update(['last_synced_at' => now()]);
        });
    }
}
