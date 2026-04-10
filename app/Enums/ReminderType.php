<?php

namespace App\Enums;

enum ReminderType: string
{
    case TaskDueSoon = 'task_due_soon';
    case TaskOverdue = 'task_overdue';
    case EventStartSoon = 'event_start_soon';
    case CollaborationInviteReceived = 'collaboration_invite_received';
    case CalendarFeedSyncFailed = 'calendar_feed_sync_failed';
    case AssistantToolCallFailed = 'assistant_tool_call_failed';
}
