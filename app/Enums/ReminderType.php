<?php

namespace App\Enums;

enum ReminderType: string
{
    case TaskDueSoon = 'task_due_soon';
    case TaskOverdue = 'task_overdue';
    case EventStartSoon = 'event_start_soon';
    case SchoolClassStartSoon = 'school_class_start_soon';
    case SchoolClassNowLive = 'school_class_now_live';
    case SchoolClassEndingSoon = 'school_class_ending_soon';
    case SchoolClassMissed = 'school_class_missed';
    case CollaborationInviteReceived = 'collaboration_invite_received';
    case DailyDueSummary = 'daily_due_summary';
    case TaskStalled = 'task_stalled';
    case ProjectDeadlineRisk = 'project_deadline_risk';
    case RecurrenceAnomaly = 'recurrence_anomaly';
    case CollaborationInviteExpiring = 'collaboration_invite_expiring';
    case CalendarFeedSyncFailed = 'calendar_feed_sync_failed';
    case CalendarFeedRecovered = 'calendar_feed_recovered';
    case CalendarFeedStaleSync = 'calendar_feed_stale_sync';
    case FocusSessionCompleted = 'focus_session_completed';
    case FocusDriftWeekly = 'focus_drift_weekly';
    case AssistantActionRequired = 'assistant_action_required';
}
