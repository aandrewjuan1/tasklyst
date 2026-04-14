<?php

namespace App\Services\Reminders;

use App\Enums\ReminderStatus;
use App\Enums\ReminderType;
use App\Enums\TaskPriority;
use App\Models\CalendarFeed;
use App\Models\CollaborationInvitation;
use App\Models\Event;
use App\Models\FocusSession;
use App\Models\Project;
use App\Models\RecurringEvent;
use App\Models\RecurringTask;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class ReminderInsightsSchedulerService
{
    public function evaluateDueInsights(?CarbonInterface $now = null): int
    {
        $now = $now ? CarbonImmutable::instance($now) : CarbonImmutable::now();
        $created = 0;

        foreach (User::query()->select('id')->cursor() as $user) {
            $created += $this->scheduleDailyDueSummary($user, $now);
            $created += $this->scheduleTaskStalled($user, $now);
            $created += $this->scheduleProjectDeadlineRisk($user, $now);
            $created += $this->scheduleRecurrenceAnomaly($user, $now);
            $created += $this->scheduleInviteExpiring($user, $now);
            $created += $this->scheduleCalendarFeedStaleSync($user, $now);
            $created += $this->scheduleFocusDriftWeekly($user, $now);
            $created += $this->scheduleAssistantActionRequired($user, $now);
        }

        return $created;
    }

    private function scheduleDailyDueSummary(User $user, CarbonImmutable $now): int
    {
        $hour = max(0, min(23, (int) config('reminders.daily_due_summary_hour', 7)));
        if ((int) $now->format('G') !== $hour) {
            return 0;
        }

        $dayStart = $now->startOfDay();
        $dayEnd = $now->endOfDay();

        $tasksDueToday = Task::query()
            ->where('user_id', $user->id)
            ->whereNull('completed_at')
            ->whereBetween('end_datetime', [$dayStart, $dayEnd])
            ->count();

        $eventsToday = Event::query()
            ->where('user_id', $user->id)
            ->whereBetween('start_datetime', [$dayStart, $dayEnd])
            ->count();

        $overdueTasks = Task::query()
            ->where('user_id', $user->id)
            ->whereNull('completed_at')
            ->whereNotNull('end_datetime')
            ->where('end_datetime', '<', $now)
            ->count();

        if (($tasksDueToday + $eventsToday + $overdueTasks) === 0) {
            return 0;
        }

        return $this->createPendingReminder(
            userId: (int) $user->id,
            remindableType: $user->getMorphClass(),
            remindableId: (int) $user->id,
            type: ReminderType::DailyDueSummary,
            scheduledAt: $dayStart->setTime($hour, 0),
            payload: [
                'date' => $dayStart->toDateString(),
                'tasks_due_today_count' => $tasksDueToday,
                'events_today_count' => $eventsToday,
                'overdue_tasks_count' => $overdueTasks,
            ],
        );
    }

    private function scheduleTaskStalled(User $user, CarbonImmutable $now): int
    {
        $stalledHours = max(1, (int) config('reminders.task_stalled_hours', 72));
        $staleBefore = $now->subHours($stalledHours);

        $tasks = Task::query()
            ->where('user_id', $user->id)
            ->whereNull('completed_at')
            ->whereIn('priority', [TaskPriority::Urgent->value, TaskPriority::High->value])
            ->where('updated_at', '<=', $staleBefore)
            ->orderBy('updated_at')
            ->limit(20)
            ->get(['id', 'title', 'updated_at']);

        $created = 0;
        foreach ($tasks as $task) {
            $created += $this->createPendingReminder(
                userId: (int) $user->id,
                remindableType: Task::class,
                remindableId: (int) $task->id,
                type: ReminderType::TaskStalled,
                scheduledAt: $now->startOfHour(),
                payload: [
                    'task_id' => (int) $task->id,
                    'task_title' => (string) $task->title,
                    'hours_stalled' => $stalledHours,
                    'last_updated_at' => $task->updated_at?->toIso8601String(),
                ],
            );
        }

        return $created;
    }

    private function scheduleProjectDeadlineRisk(User $user, CarbonImmutable $now): int
    {
        $days = max(1, (int) config('reminders.project_deadline_risk_days', 7));
        $minOpenTasks = max(1, (int) config('reminders.project_deadline_risk_min_open_tasks', 3));
        $deadline = $now->copy()->addDays($days)->endOfDay();

        $projects = Project::query()
            ->where('user_id', $user->id)
            ->whereNotNull('end_datetime')
            ->whereBetween('end_datetime', [$now->copy()->startOfDay(), $deadline])
            ->withCount(['tasks as open_tasks_count' => fn ($query) => $query->whereNull('completed_at')])
            ->get(['id', 'name', 'end_datetime']);

        $created = 0;
        foreach ($projects as $project) {
            if ((int) ($project->open_tasks_count ?? 0) < $minOpenTasks) {
                continue;
            }

            $created += $this->createPendingReminder(
                userId: (int) $user->id,
                remindableType: $project->getMorphClass(),
                remindableId: (int) $project->id,
                type: ReminderType::ProjectDeadlineRisk,
                scheduledAt: $now->startOfHour(),
                payload: [
                    'project_id' => (int) $project->id,
                    'project_name' => (string) $project->name,
                    'project_end_at' => $project->end_datetime?->toIso8601String(),
                    'open_tasks_count' => (int) ($project->open_tasks_count ?? 0),
                ],
            );
        }

        return $created;
    }

    private function scheduleRecurrenceAnomaly(User $user, CarbonImmutable $now): int
    {
        $windowDays = max(1, (int) config('reminders.recurrence_anomaly_window_days', 14));
        $minExceptions = max(1, (int) config('reminders.recurrence_anomaly_min_exceptions', 3));
        $since = $now->subDays($windowDays);
        $created = 0;

        $taskSeries = RecurringTask::query()
            ->whereHas('task', fn ($query) => $query->where('user_id', $user->id))
            ->withCount(['taskExceptions as recent_exceptions_count' => fn ($query) => $query->where('created_at', '>=', $since)])
            ->with('task:id,title')
            ->get(['id', 'task_id']);

        foreach ($taskSeries as $series) {
            if ((int) ($series->recent_exceptions_count ?? 0) < $minExceptions) {
                continue;
            }

            $created += $this->createPendingReminder(
                userId: (int) $user->id,
                remindableType: RecurringTask::class,
                remindableId: (int) $series->id,
                type: ReminderType::RecurrenceAnomaly,
                scheduledAt: $now->startOfDay(),
                payload: [
                    'recurring_kind' => 'task',
                    'recurring_id' => (int) $series->id,
                    'entity_id' => (int) $series->task_id,
                    'entity_title' => (string) ($series->task?->title ?? ''),
                    'exceptions_count' => (int) ($series->recent_exceptions_count ?? 0),
                    'window_days' => $windowDays,
                ],
            );
        }

        $eventSeries = RecurringEvent::query()
            ->whereHas('event', fn ($query) => $query->where('user_id', $user->id))
            ->withCount(['eventExceptions as recent_exceptions_count' => fn ($query) => $query->where('created_at', '>=', $since)])
            ->with('event:id,title')
            ->get(['id', 'event_id']);

        foreach ($eventSeries as $series) {
            if ((int) ($series->recent_exceptions_count ?? 0) < $minExceptions) {
                continue;
            }

            $created += $this->createPendingReminder(
                userId: (int) $user->id,
                remindableType: $series->getMorphClass(),
                remindableId: (int) $series->id,
                type: ReminderType::RecurrenceAnomaly,
                scheduledAt: $now->startOfDay(),
                payload: [
                    'recurring_kind' => 'event',
                    'recurring_id' => (int) $series->id,
                    'entity_id' => (int) $series->event_id,
                    'entity_title' => (string) ($series->event?->title ?? ''),
                    'exceptions_count' => (int) ($series->recent_exceptions_count ?? 0),
                    'window_days' => $windowDays,
                ],
            );
        }

        return $created;
    }

    private function scheduleInviteExpiring(User $user, CarbonImmutable $now): int
    {
        $hoursBefore = max(1, (int) config('reminders.collaboration_invite_expiring_hours_before', 24));
        $windowStart = $now->copy()->addHours($hoursBefore)->startOfHour();
        $windowEnd = $windowStart->copy()->addHour();
        $created = 0;

        $expiringInvites = CollaborationInvitation::query()
            ->where('inviter_id', $user->id)
            ->where('status', 'pending')
            ->whereBetween('expires_at', [$windowStart, $windowEnd])
            ->get(['id', 'invitee_email', 'expires_at', 'collaboratable_type', 'collaboratable_id']);

        foreach ($expiringInvites as $invitation) {
            $created += $this->createPendingReminder(
                userId: (int) $user->id,
                remindableType: CollaborationInvitation::class,
                remindableId: (int) $invitation->id,
                type: ReminderType::CollaborationInviteExpiring,
                scheduledAt: $windowStart,
                payload: [
                    'invitation_id' => (int) $invitation->id,
                    'invitee_email' => (string) $invitation->invitee_email,
                    'expires_at' => $invitation->expires_at?->toIso8601String(),
                    'collaboratable_type' => (string) $invitation->collaboratable_type,
                    'collaboratable_id' => (int) $invitation->collaboratable_id,
                ],
            );
        }

        return $created;
    }

    private function scheduleCalendarFeedStaleSync(User $user, CarbonImmutable $now): int
    {
        $staleHours = max(1, (int) config('reminders.calendar_feed_stale_sync_hours', 6));
        $cutoff = $now->subHours($staleHours);
        $cooldownMinutes = max(1, (int) config('reminders.calendar_feed_stale_sync_cooldown_minutes', 180));
        $created = 0;

        $feeds = CalendarFeed::query()
            ->where('user_id', $user->id)
            ->where('sync_enabled', true)
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_synced_at')
                    ->orWhere('last_synced_at', '<=', $cutoff);
            })
            ->get(['id', 'name', 'last_synced_at']);

        foreach ($feeds as $feed) {
            $recentExists = Reminder::query()
                ->where('user_id', $user->id)
                ->where('remindable_type', CalendarFeed::class)
                ->where('remindable_id', $feed->id)
                ->where('type', ReminderType::CalendarFeedStaleSync->value)
                ->where('created_at', '>=', $now->subMinutes($cooldownMinutes))
                ->exists();

            if ($recentExists) {
                continue;
            }

            $created += $this->createPendingReminder(
                userId: (int) $user->id,
                remindableType: CalendarFeed::class,
                remindableId: (int) $feed->id,
                type: ReminderType::CalendarFeedStaleSync,
                scheduledAt: $now->startOfMinute(),
                payload: [
                    'feed_id' => (int) $feed->id,
                    'feed_name' => (string) $feed->name,
                    'last_synced_at' => $feed->last_synced_at?->toIso8601String(),
                    'stale_hours' => $staleHours,
                ],
            );
        }

        return $created;
    }

    private function scheduleFocusDriftWeekly(User $user, CarbonImmutable $now): int
    {
        $targetDay = max(0, min(6, (int) config('reminders.focus_drift_weekly_day_of_week', 1)));
        $targetHour = max(0, min(23, (int) config('reminders.focus_drift_weekly_hour', 8)));
        if ((int) $now->dayOfWeek !== $targetDay || (int) $now->format('G') !== $targetHour) {
            return 0;
        }

        $weekStart = $now->subWeek()->startOfWeek();
        $weekEnd = $weekStart->endOfWeek();

        $sessions = FocusSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('started_at', [$weekStart, $weekEnd])
            ->get(['duration_seconds', 'completed', 'started_at']);

        if ($sessions->isEmpty()) {
            return 0;
        }

        $planned = (int) $sessions->sum(fn (FocusSession $session) => max(0, (int) $session->duration_seconds));
        $completed = (int) $sessions->where('completed', true)->sum(fn (FocusSession $session) => max(0, (int) $session->duration_seconds));

        return $this->createPendingReminder(
            userId: (int) $user->id,
            remindableType: $user->getMorphClass(),
            remindableId: (int) $user->id,
            type: ReminderType::FocusDriftWeekly,
            scheduledAt: $now->startOfHour(),
            payload: [
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
                'planned_seconds' => $planned,
                'completed_seconds' => $completed,
                'completion_ratio' => $planned > 0 ? round($completed / $planned, 2) : 0,
            ],
        );
    }

    private function scheduleAssistantActionRequired(User $user, CarbonImmutable $now): int
    {
        $cooldownMinutes = max(1, (int) config('reminders.assistant_action_required_cooldown_minutes', 30));
        $recentExists = Reminder::query()
            ->where('user_id', $user->id)
            ->where('type', ReminderType::AssistantActionRequired->value)
            ->where('created_at', '>=', $now->subMinutes($cooldownMinutes))
            ->exists();

        if ($recentExists) {
            return 0;
        }

        $thread = TaskAssistantThread::query()
            ->where('user_id', $user->id)
            ->whereNotNull('metadata')
            ->latest('updated_at')
            ->first();

        if (! $thread instanceof TaskAssistantThread) {
            return 0;
        }

        $conversationState = is_array(data_get($thread->metadata, 'conversation_state'))
            ? data_get($thread->metadata, 'conversation_state')
            : [];
        $pendingScheduleFallback = data_get($conversationState, 'pending_schedule_fallback');
        if (! is_array($pendingScheduleFallback)) {
            return 0;
        }

        $scheduleData = is_array($pendingScheduleFallback['schedule_data'] ?? null)
            ? $pendingScheduleFallback['schedule_data']
            : [];
        $proposals = is_array($scheduleData['proposals'] ?? null) ? $scheduleData['proposals'] : [];
        $pendingCount = count(array_filter($proposals, fn (mixed $proposal): bool => is_array($proposal) && (($proposal['status'] ?? 'pending') === 'pending')));
        if ($pendingCount <= 0) {
            return 0;
        }

        return $this->createPendingReminder(
            userId: (int) $user->id,
            remindableType: $thread->getMorphClass(),
            remindableId: (int) $thread->id,
            type: ReminderType::AssistantActionRequired,
            scheduledAt: $now->startOfMinute(),
            payload: [
                'thread_id' => (int) $thread->id,
                'thread_title' => (string) ($thread->title ?? ''),
                'pending_proposals_count' => $pendingCount,
            ],
        );
    }

    private function createPendingReminder(
        int $userId,
        string $remindableType,
        int $remindableId,
        ReminderType $type,
        CarbonImmutable $scheduledAt,
        array $payload,
    ): int {
        $existing = Reminder::query()
            ->where('user_id', $userId)
            ->where('remindable_type', $remindableType)
            ->where('remindable_id', $remindableId)
            ->where('type', $type->value)
            ->where('scheduled_at', $scheduledAt)
            ->where('status', ReminderStatus::Pending->value)
            ->exists();

        if ($existing) {
            return 0;
        }

        Reminder::query()->create([
            'user_id' => $userId,
            'remindable_type' => $remindableType,
            'remindable_id' => $remindableId,
            'type' => $type,
            'scheduled_at' => $scheduledAt,
            'status' => ReminderStatus::Pending,
            'payload' => $payload,
        ]);

        return 1;
    }
}
