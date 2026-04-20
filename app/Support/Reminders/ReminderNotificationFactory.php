<?php

namespace App\Support\Reminders;

use App\Enums\ReminderType;
use App\Models\Reminder;
use App\Notifications\AssistantActionRequiredNotification;
use App\Notifications\AssistantToolCallFailedNotification;
use App\Notifications\CalendarFeedRecoveredNotification;
use App\Notifications\CalendarFeedStaleSyncNotification;
use App\Notifications\CalendarFeedSyncFailedNotification;
use App\Notifications\CollaborationInvitationReceivedNotification;
use App\Notifications\CollaborationInviteExpiringNotification;
use App\Notifications\DailyDueSummaryNotification;
use App\Notifications\EventStartSoonNotification;
use App\Notifications\FocusDriftWeeklyNotification;
use App\Notifications\FocusSessionCompletedNotification;
use App\Notifications\ProjectDeadlineRiskNotification;
use App\Notifications\RecurrenceAnomalyNotification;
use App\Notifications\SchoolClassEndingSoonNotification;
use App\Notifications\SchoolClassMissedNotification;
use App\Notifications\SchoolClassNowLiveNotification;
use App\Notifications\SchoolClassStartSoonNotification;
use App\Notifications\TaskDueSoonNotification;
use App\Notifications\TaskOverdueNotification;
use App\Notifications\TaskStalledNotification;

final class ReminderNotificationFactory
{
    public function make(Reminder $reminder): ?object
    {
        $type = $reminder->type;
        $payload = is_array($reminder->payload ?? null) ? $reminder->payload : [];

        if ($type === ReminderType::TaskDueSoon) {
            return new TaskDueSoonNotification(
                taskId: (int) ($payload['task_id'] ?? $reminder->remindable_id),
                taskTitle: (string) ($payload['task_title'] ?? ''),
                dueAtIso: isset($payload['due_at']) ? (string) $payload['due_at'] : null,
                offsetMinutes: isset($payload['offset_minutes']) ? (int) $payload['offset_minutes'] : null,
            );
        }

        if ($type === ReminderType::TaskOverdue) {
            return new TaskOverdueNotification(
                taskId: (int) ($payload['task_id'] ?? $reminder->remindable_id),
                taskTitle: (string) ($payload['task_title'] ?? ''),
                dueAtIso: isset($payload['due_at']) ? (string) $payload['due_at'] : null,
            );
        }

        if ($type === ReminderType::EventStartSoon) {
            return new EventStartSoonNotification(
                eventId: (int) ($payload['event_id'] ?? $reminder->remindable_id),
                eventTitle: (string) ($payload['event_title'] ?? ''),
                startAtIso: isset($payload['start_at']) ? (string) $payload['start_at'] : null,
                offsetMinutes: isset($payload['offset_minutes']) ? (int) $payload['offset_minutes'] : null,
            );
        }

        if ($type === ReminderType::SchoolClassStartSoon) {
            return new SchoolClassStartSoonNotification(
                schoolClassId: (int) ($payload['school_class_id'] ?? $reminder->remindable_id),
                subjectName: (string) ($payload['subject_name'] ?? ''),
                startsAtIso: isset($payload['starts_at']) ? (string) $payload['starts_at'] : null,
                endsAtIso: isset($payload['ends_at']) ? (string) $payload['ends_at'] : null,
                offsetMinutes: isset($payload['offset_minutes']) ? (int) $payload['offset_minutes'] : null,
            );
        }

        if ($type === ReminderType::SchoolClassNowLive) {
            return new SchoolClassNowLiveNotification(
                schoolClassId: (int) ($payload['school_class_id'] ?? $reminder->remindable_id),
                subjectName: (string) ($payload['subject_name'] ?? ''),
                startsAtIso: isset($payload['starts_at']) ? (string) $payload['starts_at'] : null,
                endsAtIso: isset($payload['ends_at']) ? (string) $payload['ends_at'] : null,
            );
        }

        if ($type === ReminderType::SchoolClassEndingSoon) {
            return new SchoolClassEndingSoonNotification(
                schoolClassId: (int) ($payload['school_class_id'] ?? $reminder->remindable_id),
                subjectName: (string) ($payload['subject_name'] ?? ''),
                startsAtIso: isset($payload['starts_at']) ? (string) $payload['starts_at'] : null,
                endsAtIso: isset($payload['ends_at']) ? (string) $payload['ends_at'] : null,
                offsetMinutes: isset($payload['offset_minutes']) ? (int) $payload['offset_minutes'] : null,
            );
        }

        if ($type === ReminderType::SchoolClassMissed) {
            return new SchoolClassMissedNotification(
                schoolClassId: (int) ($payload['school_class_id'] ?? $reminder->remindable_id),
                subjectName: (string) ($payload['subject_name'] ?? ''),
                startsAtIso: isset($payload['starts_at']) ? (string) $payload['starts_at'] : null,
                endsAtIso: isset($payload['ends_at']) ? (string) $payload['ends_at'] : null,
            );
        }

        if ($type === ReminderType::CollaborationInviteReceived) {
            return new CollaborationInvitationReceivedNotification(
                invitationId: (int) ($payload['invitation_id'] ?? $payload['id'] ?? 0),
                inviteeEmail: (string) ($payload['invitee_email'] ?? ''),
                collaboratableType: (string) ($payload['collaboratable_type'] ?? ''),
                collaboratableId: (int) ($payload['collaboratable_id'] ?? 0),
                permission: isset($payload['permission']) ? (string) $payload['permission'] : null,
            );
        }

        if ($type === ReminderType::DailyDueSummary) {
            return new DailyDueSummaryNotification(
                date: (string) ($payload['date'] ?? now()->toDateString()),
                tasksDueTodayCount: (int) ($payload['tasks_due_today_count'] ?? 0),
                eventsTodayCount: (int) ($payload['events_today_count'] ?? 0),
                overdueTasksCount: (int) ($payload['overdue_tasks_count'] ?? 0),
            );
        }

        if ($type === ReminderType::TaskStalled) {
            return new TaskStalledNotification(
                taskId: (int) ($payload['task_id'] ?? $reminder->remindable_id),
                taskTitle: (string) ($payload['task_title'] ?? ''),
                hoursStalled: (int) ($payload['hours_stalled'] ?? 0),
            );
        }

        if ($type === ReminderType::ProjectDeadlineRisk) {
            return new ProjectDeadlineRiskNotification(
                projectId: (int) ($payload['project_id'] ?? $reminder->remindable_id),
                projectName: (string) ($payload['project_name'] ?? ''),
                projectEndAt: isset($payload['project_end_at']) ? (string) $payload['project_end_at'] : null,
                openTasksCount: (int) ($payload['open_tasks_count'] ?? 0),
            );
        }

        if ($type === ReminderType::RecurrenceAnomaly) {
            return new RecurrenceAnomalyNotification(
                recurringKind: (string) ($payload['recurring_kind'] ?? 'task'),
                entityId: (int) ($payload['entity_id'] ?? 0),
                entityTitle: (string) ($payload['entity_title'] ?? ''),
                exceptionsCount: (int) ($payload['exceptions_count'] ?? 0),
                windowDays: (int) ($payload['window_days'] ?? 14),
            );
        }

        if ($type === ReminderType::CollaborationInviteExpiring) {
            return new CollaborationInviteExpiringNotification(
                invitationId: (int) ($payload['invitation_id'] ?? $reminder->remindable_id),
                inviteeEmail: (string) ($payload['invitee_email'] ?? ''),
                expiresAtIso: isset($payload['expires_at']) ? (string) $payload['expires_at'] : null,
            );
        }

        if ($type === ReminderType::CalendarFeedSyncFailed) {
            return new CalendarFeedSyncFailedNotification(
                feedId: (int) ($payload['feed_id'] ?? $payload['id'] ?? 0),
                feedName: isset($payload['feed_name']) ? (string) $payload['feed_name'] : null,
                reason: isset($payload['reason']) ? (string) $payload['reason'] : null,
            );
        }

        if ($type === ReminderType::CalendarFeedRecovered) {
            return new CalendarFeedRecoveredNotification(
                feedId: (int) ($payload['feed_id'] ?? $reminder->remindable_id),
                feedName: isset($payload['feed_name']) ? (string) $payload['feed_name'] : null,
            );
        }

        if ($type === ReminderType::CalendarFeedStaleSync) {
            return new CalendarFeedStaleSyncNotification(
                feedId: (int) ($payload['feed_id'] ?? $reminder->remindable_id),
                feedName: isset($payload['feed_name']) ? (string) $payload['feed_name'] : null,
                lastSyncedAt: isset($payload['last_synced_at']) ? (string) $payload['last_synced_at'] : null,
                staleHours: (int) ($payload['stale_hours'] ?? 6),
            );
        }

        if ($type === ReminderType::FocusSessionCompleted) {
            return new FocusSessionCompletedNotification(
                focusSessionId: (int) ($payload['focus_session_id'] ?? $reminder->remindable_id),
                taskId: isset($payload['task_id']) ? (int) $payload['task_id'] : null,
                durationSeconds: (int) ($payload['duration_seconds'] ?? 0),
            );
        }

        if ($type === ReminderType::FocusDriftWeekly) {
            return new FocusDriftWeeklyNotification(
                weekStart: (string) ($payload['week_start'] ?? ''),
                weekEnd: (string) ($payload['week_end'] ?? ''),
                plannedSeconds: (int) ($payload['planned_seconds'] ?? 0),
                completedSeconds: (int) ($payload['completed_seconds'] ?? 0),
            );
        }

        if ($type === ReminderType::AssistantActionRequired) {
            return new AssistantActionRequiredNotification(
                threadId: (int) ($payload['thread_id'] ?? $reminder->remindable_id),
                threadTitle: (string) ($payload['thread_title'] ?? ''),
                pendingProposalsCount: (int) ($payload['pending_proposals_count'] ?? 0),
            );
        }

        if ($type === ReminderType::AssistantToolCallFailed) {
            return new AssistantToolCallFailedNotification(
                toolCallId: (int) ($payload['tool_call_id'] ?? $payload['id'] ?? 0),
                toolName: (string) ($payload['tool_name'] ?? ''),
                operationToken: isset($payload['operation_token']) ? (string) $payload['operation_token'] : null,
                threadId: isset($payload['thread_id']) ? (int) $payload['thread_id'] : null,
                messageId: isset($payload['message_id']) ? (int) $payload['message_id'] : null,
                error: isset($payload['error']) ? (string) $payload['error'] : null,
            );
        }

        return null;
    }
}
