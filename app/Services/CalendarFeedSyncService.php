<?php

namespace App\Services;

use App\Enums\TaskComplexity;
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

        $events = $this->filterEventsWithinSyncWindow($events);

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
                $sourceUrl = $this->extractUrlFromDescription($description);

                $start = $event['dtstart'] ?? null;
                $end = $event['dtend'] ?? null;

                if (! $start instanceof \Carbon\CarbonInterface) {
                    $start = null;
                }

                if (! $end instanceof \Carbon\CarbonInterface) {
                    $end = null;
                }

                // When both start and end exist and are identical, treat this as a due-only item.
                // Keep only the due date on the task to avoid duplicating the same datetime.
                if ($start !== null && $end !== null && $start->equalTo($end)) {
                    $start = null;
                }

                $isExam = $this->isExamEvent($summary, $description);

                Task::query()->updateOrCreate(
                    [
                        'user_id' => $feed->user_id,
                        'source_type' => TaskSourceType::Brightspace,
                        'source_id' => $uid,
                    ],
                    [
                        'title' => $summary ?: __('Untitled'),
                        'start_datetime' => $start,
                        'end_datetime' => $end,
                        'source_url' => $sourceUrl,
                        'calendar_feed_id' => $feed->id,
                        'status' => TaskStatus::ToDo,
                        'priority' => $isExam ? TaskPriority::High : TaskPriority::Medium,
                        'complexity' => $isExam ? TaskComplexity::Complex : TaskComplexity::Moderate,
                        'project_id' => null,
                        'event_id' => null,
                    ]
                );
            }

            $feed->update(['last_synced_at' => now()]);
        });
    }

    /**
     * Limit synced events to a reasonable time window so we don't
     * import thousands of long‑past or far‑future items from feeds.
     *
     * Currently:
     * - Include events that ended within the last 6 months.
     * - Skip events that ended more than 6 months ago.
     * - Skip events that start more than 1 year in the future.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    private function filterEventsWithinSyncWindow(array $events): array
    {
        $today = now()->startOfDay();
        $pastLimit = $today->copy()->subMonths(6)->startOfDay();
        $futureLimit = $today->copy()->addYear()->endOfDay();

        return array_values(array_filter($events, static function (array $event) use ($pastLimit, $futureLimit): bool {
            $start = $event['dtstart'] ?? null;
            $end = $event['dtend'] ?? null;

            if (! $start instanceof \Carbon\CarbonInterface && ! $end instanceof \Carbon\CarbonInterface) {
                return true;
            }

            $effectiveEnd = $end instanceof \Carbon\CarbonInterface ? $end : $start;
            $effectiveStart = $start instanceof \Carbon\CarbonInterface ? $start : $effectiveEnd;

            if ($effectiveEnd instanceof \Carbon\CarbonInterface && $effectiveEnd->lt($pastLimit)) {
                return false;
            }

            if ($effectiveStart instanceof \Carbon\CarbonInterface && $effectiveStart->gt($futureLimit)) {
                return false;
            }

            return true;
        }));
    }

    /**
     * Whether the VEVENT is exam-related (Brightspace): "EXAM" or "exam" in summary or description.
     */
    private function isExamEvent(?string $summary, ?string $description): bool
    {
        $keyword = 'exam';
        $summaryLower = $summary !== null ? strtolower($summary) : '';
        $descriptionLower = $description !== null ? strtolower($description) : '';

        return str_contains($summaryLower, $keyword) || str_contains($descriptionLower, $keyword);
    }

    private function extractUrlFromDescription(?string $description): ?string
    {
        if ($description === null || trim($description) === '') {
            return null;
        }

        if (! preg_match_all('~https?://\S+~i', $description, $matches)) {
            return null;
        }

        $candidates = $matches[0] ?? [];
        if ($candidates === []) {
            return null;
        }

        // Prefer Brightspace calendar "View event" links over other URLs (e.g. dropbox submission links).
        $url = $candidates[0];
        foreach ($candidates as $candidate) {
            if (str_contains($candidate, '/d2l/le/calendar/') && str_contains($candidate, 'detailsview')) {
                $url = $candidate;
                break;
            }
        }

        // Brightspace often encodes newlines as literal "\n" sequences inside DESCRIPTION.
        // Treat those (and real newlines) as boundaries so we don't capture trailing labels like "View".
        $parts = preg_split("/\\\\n|\r\n|\r|\n/", $url);
        $url = $parts[0] ?? $url;

        $url = rtrim($url, ".,);]>\"'");

        return $url !== '' ? $url : null;
    }
}
