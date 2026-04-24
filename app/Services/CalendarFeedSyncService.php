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
use App\Models\User;
use App\Notifications\CalendarFeedSyncCompletedNotification;
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
        private UserNotificationBroadcastService $userNotificationBroadcastService,
    ) {}

    public function sync(CalendarFeed $feed, bool $notifyUserOnSuccess = false): CalendarFeedSyncResult
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

        $feed->loadMissing('user');

        $eventsInRawFeed = count($events);
        $importPastMonths = $feed->resolvedImportPastMonths();
        $events = $this->filterEventsWithinSyncWindow(
            $events,
            $importPastMonths,
            (bool) $feed->exclude_overdue_items
        );
        $eventsInWindow = count($events);

        if ($events === []) {
            $feed->update(['last_synced_at' => now()]);

            $result = new CalendarFeedSyncResult(
                CalendarFeedSyncStatus::Completed,
                eventsInWindow: 0,
                eventsInRawFeed: $eventsInRawFeed,
            );
            $this->notifyUserOfSuccessfulSync($feed, $result, $notifyUserOnSuccess);

            return $result;
        }

        $stats = DB::transaction(function () use ($feed, $events): array {
            $stats = $this->importBrightspaceEventsUsingUpsert($feed, $events);
            $feed->update(['last_synced_at' => now()]);

            return $stats;
        });

        $this->createSyncRecoveredReminderIfAllowed($feed);

        $result = new CalendarFeedSyncResult(
            CalendarFeedSyncStatus::Completed,
            itemsApplied: $stats['itemsApplied'],
            eventsInWindow: $eventsInWindow,
            eventsInRawFeed: $eventsInRawFeed,
            eventsSkippedNoUid: $stats['eventsSkippedNoUid'],
            tasksCreated: $stats['tasksCreated'],
            tasksUpdated: $stats['tasksUpdated'],
        );
        $this->notifyUserOfSuccessfulSync($feed, $result, $notifyUserOnSuccess);

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array{itemsApplied: int, eventsSkippedNoUid: int, tasksCreated: int, tasksUpdated: int}
     */
    private function importBrightspaceEventsUsingUpsert(CalendarFeed $feed, array $events): array
    {
        $skippedNoUid = 0;
        $rows = [];

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
            $normalizedTitle = $this->normalizeBrightspaceTitle($summary);

            $start = $event['dtstart'] ?? null;
            $end = $event['dtend'] ?? null;

            if (! $start instanceof \Carbon\CarbonInterface) {
                $start = null;
            }

            if (! $end instanceof \Carbon\CarbonInterface) {
                $end = null;
            }

            if ($start !== null && $end !== null && $start->equalTo($end)) {
                $start = null;
            }

            $isExam = $this->isExamEvent($summary, $description);
            $teacherName = null;
            $subjectName = null;

            if (is_string($location) && trim($location) !== '') {
                $location = trim($location);

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

            $rows[] = [
                'source_id' => $uid,
                'title' => $normalizedTitle !== '' ? $normalizedTitle : __('Untitled'),
                'description' => null,
                'teacher_name' => $teacherName,
                'subject_name' => $subjectName,
                'start_datetime' => $this->formatDateTimeForStorage($start),
                'end_datetime' => $this->formatDateTimeForStorage($end),
                'source_url' => $sourceUrl,
                'calendar_feed_id' => $feed->id,
                'status' => TaskStatus::ToDo->value,
                'priority' => ($isExam ? TaskPriority::High : TaskPriority::Medium)->value,
                'complexity' => ($isExam ? TaskComplexity::Complex : TaskComplexity::Moderate)->value,
                'project_id' => null,
                'event_id' => null,
            ];
        }

        $itemsApplied = count($rows);
        if ($rows === []) {
            return [
                'itemsApplied' => 0,
                'eventsSkippedNoUid' => $skippedNoUid,
                'tasksCreated' => 0,
                'tasksUpdated' => 0,
            ];
        }

        $sourceIds = array_column($rows, 'source_id');
        $existingIds = Task::query()
            ->where('user_id', $feed->user_id)
            ->where('source_type', TaskSourceType::Brightspace->value)
            ->whereIn('source_id', $sourceIds)
            ->pluck('source_id')
            ->all();

        $existingSet = array_fill_keys($existingIds, true);

        $tasksCreated = 0;
        $tasksUpdated = 0;
        foreach ($rows as $row) {
            if (isset($existingSet[$row['source_id']])) {
                $tasksUpdated++;
            } else {
                $tasksCreated++;
            }
        }

        $sourceType = TaskSourceType::Brightspace->value;
        $now = now()->toDateTimeString();
        $chunkSize = max(1, (int) config('calendar_feeds.task_upsert_chunk_size', 150));

        $updateColumns = [
            'title',
            'description',
            'teacher_name',
            'subject_name',
            'start_datetime',
            'end_datetime',
            'source_url',
            'calendar_feed_id',
            'status',
            'priority',
            'complexity',
            'project_id',
            'event_id',
            'updated_at',
            'deleted_at',
        ];

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $values[] = array_merge($row, [
                    'user_id' => $feed->user_id,
                    'source_type' => $sourceType,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]);
            }

            Task::upsert(
                $values,
                ['user_id', 'source_type', 'source_id'],
                $updateColumns
            );
        }

        return [
            'itemsApplied' => $itemsApplied,
            'eventsSkippedNoUid' => $skippedNoUid,
            'tasksCreated' => $tasksCreated,
            'tasksUpdated' => $tasksUpdated,
        ];
    }

    private function formatDateTimeForStorage(?\Carbon\CarbonInterface $dateTime): ?string
    {
        return $dateTime?->copy()
            ->setTimezone(config('app.timezone'))
            ->format('Y-m-d H:i:s');
    }

    private function notifyUserOfSuccessfulSync(CalendarFeed $feed, CalendarFeedSyncResult $result, bool $notifyUserOnSuccess): void
    {
        if (! $notifyUserOnSuccess) {
            return;
        }

        if ($result->status !== CalendarFeedSyncStatus::Completed) {
            return;
        }

        $user = $feed->relationLoaded('user') && $feed->user instanceof User
            ? $feed->user
            : User::query()->find((int) $feed->user_id);

        if ($user === null) {
            return;
        }

        $user->notify(new CalendarFeedSyncCompletedNotification(
            feedId: (int) $feed->id,
            feedName: $feed->name,
            itemsApplied: $result->itemsApplied,
            tasksCreated: $result->tasksCreated,
            tasksUpdated: $result->tasksUpdated,
            eventsInWindow: $result->eventsInWindow,
            eventsInRawFeed: $result->eventsInRawFeed,
            eventsSkippedNoUid: $result->eventsSkippedNoUid,
        ));

        $this->userNotificationBroadcastService->broadcastInboxUpdated($user);
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
     * - Include events that ended on or after start of today minus N calendar months (N from the feed owner).
     * - Skip events that ended strictly before that cutoff.
     * - Skip events that start more than 1 year after today (end of that day).
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    private function filterEventsWithinSyncWindow(array $events, int $importPastMonths, bool $excludeOverdueItems): array
    {
        $now = now();
        $today = $now->copy()->startOfDay();
        $pastLimit = $today->copy()->subMonths($importPastMonths)->startOfDay();
        $futureLimit = $today->copy()->addYear()->endOfDay();

        return array_values(array_filter($events, static function (array $event) use ($excludeOverdueItems, $now, $pastLimit, $futureLimit): bool {
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

            if ($excludeOverdueItems && $effectiveEnd instanceof \Carbon\CarbonInterface && $effectiveEnd->lt($now)) {
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

    private function normalizeBrightspaceTitle(?string $summary): string
    {
        if ($summary === null) {
            return '';
        }

        $title = trim($summary);
        if ($title === '') {
            return '';
        }

        // Brightspace often appends status-like labels to SUMMARY.
        // Strip only known trailing labels to keep the original title intact.
        $title = (string) preg_replace(
            '/\s*-\s*(Due|Availability\s+Ends|Available)\s*$/i',
            '',
            $title
        );

        return trim($title);
    }
}
