<?php

namespace App\Services;

use App\DataTransferObjects\CalendarFeed\CalendarFeedSyncResult;
use App\Enums\CalendarFeedSyncStatus;
use App\Enums\ReminderStatus;
use App\Enums\ReminderType;
use App\Enums\TaskComplexity;
use App\Enums\TaskPriority;
use App\Enums\TaskSourceType;
use App\Enums\TaskStatus;
use App\Models\CalendarFeed;
use App\Models\Reminder;
use App\Models\Task;
use App\Services\Reminders\ReminderDispatcherService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CalendarFeedSyncService
{
    public function __construct(
        private IcsParserService $icsParserService,
        private ReminderDispatcherService $reminderDispatcherService,
    ) {}

    public function sync(CalendarFeed $feed): CalendarFeedSyncResult
    {
        if (! $feed->sync_enabled) {
            return new CalendarFeedSyncResult(CalendarFeedSyncStatus::SyncDisabled);
        }

        try {
            /** @var Response $response */
            $response = Http::timeout(15)->get($feed->feed_url);
            if (! $response->successful()) {
                $this->createSyncFailedReminderIfAllowed($feed, 'http_'.$response->status());

                return new CalendarFeedSyncResult(
                    CalendarFeedSyncStatus::HttpFailed,
                    httpStatus: $response->status(),
                );
            }

            $body = $response->body();
            if ($body === null || trim($body) === '') {
                return new CalendarFeedSyncResult(CalendarFeedSyncStatus::EmptyBody);
            }

            $events = $this->icsParserService->parse($body);
        } catch (\Throwable $e) {
            Log::error('Failed to fetch or parse calendar feed.', [
                'feed_id' => $feed->id,
                'user_id' => $feed->user_id,
                'exception' => $e,
            ]);

            $this->createSyncFailedReminderIfAllowed($feed, 'exception');

            return new CalendarFeedSyncResult(CalendarFeedSyncStatus::Exception);
        }

        $eventsInRawFeed = count($events);
        $events = $this->filterEventsWithinSyncWindow($events);
        $eventsInWindow = count($events);

        if ($events === []) {
            $feed->update(['last_synced_at' => now()]);

            return new CalendarFeedSyncResult(
                CalendarFeedSyncStatus::Completed,
                eventsInWindow: 0,
                eventsInRawFeed: $eventsInRawFeed,
            );
        }

        $stats = DB::transaction(function () use ($feed, $events): array {
            $itemsApplied = 0;
            $skippedNoUid = 0;
            $created = 0;
            $updated = 0;

            foreach ($events as $event) {
                if (! isset($event['uid'])) {
                    $skippedNoUid++;

                    continue;
                }

                $uid = (string) $event['uid'];
                $summary = $event['summary'] ?? null;
                $description = $event['description'] ?? null;
                $location = $event['location'] ?? null;
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
                $teacherName = null;
                $subjectName = null;

                if (is_string($location) && trim($location) !== '') {
                    $location = trim($location);

                    // Brightspace locations commonly look like "TEACHER_Subject Name (Section)".
                    $parts = explode('_', $location, 2);
                    if (count($parts) === 2) {
                        $maybeTeacher = trim($parts[0]);
                        $maybeSubject = trim($parts[1]);

                        if ($maybeTeacher !== '' && $maybeSubject !== '') {
                            $teacherName = $maybeTeacher;
                            $subjectName = $maybeSubject;
                        }
                    }

                    if ($subjectName === null) {
                        $subjectName = $location;
                    }
                }

                $task = Task::query()->updateOrCreate(
                    [
                        'user_id' => $feed->user_id,
                        'source_type' => TaskSourceType::Brightspace,
                        'source_id' => $uid,
                    ],
                    [
                        'title' => $summary ?: __('Untitled'),
                        'description' => null,
                        'teacher_name' => $teacherName,
                        'subject_name' => $subjectName,
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

                $itemsApplied++;
                if ($task->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            $feed->update(['last_synced_at' => now()]);

            return [
                'itemsApplied' => $itemsApplied,
                'eventsSkippedNoUid' => $skippedNoUid,
                'tasksCreated' => $created,
                'tasksUpdated' => $updated,
            ];
        });

        $this->createSyncRecoveredReminderIfAllowed($feed);

        return new CalendarFeedSyncResult(
            CalendarFeedSyncStatus::Completed,
            itemsApplied: $stats['itemsApplied'],
            eventsInWindow: $eventsInWindow,
            eventsInRawFeed: $eventsInRawFeed,
            eventsSkippedNoUid: $stats['eventsSkippedNoUid'],
            tasksCreated: $stats['tasksCreated'],
            tasksUpdated: $stats['tasksUpdated'],
        );
    }

    private function createSyncFailedReminderIfAllowed(CalendarFeed $feed, string $reason): void
    {
        $cooldownMinutes = (int) config('reminders.calendar_feed_sync_failed_cooldown_minutes', 60);
        $cooldownMinutes = max(1, $cooldownMinutes);

        $recentExists = Reminder::query()
            ->where('user_id', $feed->user_id)
            ->where('remindable_type', $feed->getMorphClass())
            ->where('remindable_id', $feed->id)
            ->where('type', ReminderType::CalendarFeedSyncFailed->value)
            ->where('created_at', '>=', now()->subMinutes($cooldownMinutes))
            ->exists();

        if ($recentExists) {
            return;
        }

        Reminder::query()->create([
            'user_id' => $feed->user_id,
            'remindable_type' => $feed->getMorphClass(),
            'remindable_id' => $feed->id,
            'type' => ReminderType::CalendarFeedSyncFailed,
            'scheduled_at' => now(),
            'status' => ReminderStatus::Pending,
            'payload' => [
                'feed_id' => $feed->id,
                'feed_name' => $feed->name,
                'reason' => $reason,
            ],
        ]);

        $this->reminderDispatcherService->queueProcessDueForRemindable($feed);
    }

    private function createSyncRecoveredReminderIfAllowed(CalendarFeed $feed): void
    {
        $cooldownMinutes = (int) config('reminders.calendar_feed_recovered_cooldown_minutes', 180);
        $cooldownMinutes = max(1, $cooldownMinutes);

        $hadRecentFailure = Reminder::query()
            ->where('user_id', $feed->user_id)
            ->where('remindable_type', $feed->getMorphClass())
            ->where('remindable_id', $feed->id)
            ->where('type', ReminderType::CalendarFeedSyncFailed->value)
            ->where('created_at', '>=', now()->subDay())
            ->exists();

        if (! $hadRecentFailure) {
            return;
        }

        $recentRecoveredExists = Reminder::query()
            ->where('user_id', $feed->user_id)
            ->where('remindable_type', $feed->getMorphClass())
            ->where('remindable_id', $feed->id)
            ->where('type', ReminderType::CalendarFeedRecovered->value)
            ->where('created_at', '>=', now()->subMinutes($cooldownMinutes))
            ->exists();

        if ($recentRecoveredExists) {
            return;
        }

        Reminder::query()->create([
            'user_id' => $feed->user_id,
            'remindable_type' => $feed->getMorphClass(),
            'remindable_id' => $feed->id,
            'type' => ReminderType::CalendarFeedRecovered,
            'scheduled_at' => now(),
            'status' => ReminderStatus::Pending,
            'payload' => [
                'feed_id' => $feed->id,
                'feed_name' => $feed->name,
            ],
        ]);

        $this->reminderDispatcherService->queueProcessDueForRemindable($feed);
    }

    /**
     * Limit synced events to a reasonable time window so we don't
     * import thousands of long‑past or far‑future items from feeds.
     *
     * Currently:
     * - Include events that ended on or after the rolling date 90 days before today (start of day).
     * - Skip events that ended more than 90 days ago.
     * - Skip events that start more than 1 year in the future.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    private function filterEventsWithinSyncWindow(array $events): array
    {
        $today = now()->startOfDay();
        $pastLimit = $today->copy()->subDays(90)->startOfDay();
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
