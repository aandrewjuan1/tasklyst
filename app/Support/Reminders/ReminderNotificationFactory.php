<?php

namespace App\Support\Reminders;

use App\Enums\ReminderType;
use App\Models\Reminder;
use App\Notifications\AssistantToolCallFailedNotification;
use App\Notifications\CalendarFeedSyncFailedNotification;
use App\Notifications\CollaborationInvitationReceivedNotification;
use App\Notifications\EventStartSoonNotification;
use App\Notifications\TaskDueSoonNotification;
use App\Notifications\TaskOverdueNotification;

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

        if ($type === ReminderType::CollaborationInviteReceived) {
            return new CollaborationInvitationReceivedNotification(
                invitationId: (int) ($payload['invitation_id'] ?? $payload['id'] ?? 0),
                inviteeEmail: (string) ($payload['invitee_email'] ?? ''),
                collaboratableType: (string) ($payload['collaboratable_type'] ?? ''),
                collaboratableId: (int) ($payload['collaboratable_id'] ?? 0),
                permission: isset($payload['permission']) ? (string) $payload['permission'] : null,
            );
        }

        if ($type === ReminderType::CalendarFeedSyncFailed) {
            return new CalendarFeedSyncFailedNotification(
                feedId: (int) ($payload['feed_id'] ?? $payload['id'] ?? 0),
                feedName: isset($payload['feed_name']) ? (string) $payload['feed_name'] : null,
                reason: isset($payload['reason']) ? (string) $payload['reason'] : null,
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
