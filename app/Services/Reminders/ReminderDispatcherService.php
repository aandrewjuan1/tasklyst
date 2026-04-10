<?php

namespace App\Services\Reminders;

use App\Enums\ReminderStatus;
use App\Enums\ReminderType;
use App\Events\UserNotificationCreated;
use App\Models\Reminder;
use App\Models\User;
use App\Notifications\AssistantToolCallFailedNotification;
use App\Notifications\CalendarFeedSyncFailedNotification;
use App\Notifications\CollaborationInvitationReceivedNotification;
use App\Notifications\EventStartSoonNotification;
use App\Notifications\TaskDueSoonNotification;
use App\Notifications\TaskOverdueNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ReminderDispatcherService
{
    /**
     * Dispatch due reminders and return count processed.
     */
    public function dispatchDue(int $limit = 200, ?CarbonInterface $now = null): int
    {
        $now ??= now();
        $limit = max(1, $limit);

        $candidates = Reminder::query()
            ->due($now)
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->get();

        $dispatched = 0;

        foreach ($candidates as $reminder) {
            $success = $this->dispatchSingleReminder((int) $reminder->id, $now);
            if ($success) {
                $dispatched++;
            }
        }

        return $dispatched;
    }

    private function dispatchSingleReminder(int $reminderId, CarbonInterface $now): bool
    {
        return DB::transaction(function () use ($reminderId, $now): bool {
            $reminder = Reminder::query()->lockForUpdate()->find($reminderId);
            if (! $reminder) {
                return false;
            }

            if ($reminder->status !== ReminderStatus::Pending) {
                return false;
            }

            $effectiveDueAt = $reminder->snoozed_until ?? $reminder->scheduled_at;
            if ($effectiveDueAt === null || $effectiveDueAt->gt($now)) {
                return false;
            }

            $user = User::query()->find((int) $reminder->user_id);
            if (! $user) {
                // Can't notify; cancel to avoid infinite retries.
                $reminder->status = ReminderStatus::Cancelled;
                $reminder->cancelled_at = $now;
                $reminder->save();

                return false;
            }

            $notification = $this->buildNotification($reminder);
            if ($notification === null) {
                $reminder->status = ReminderStatus::Cancelled;
                $reminder->cancelled_at = $now;
                $reminder->save();

                return false;
            }

            try {
                $user->notify($notification);
                event(new UserNotificationCreated(
                    userId: (int) $user->id,
                    unreadCount: $user->unreadNotifications()->count(),
                ));
            } catch (\Throwable $exception) {
                $payload = is_array($reminder->payload ?? null) ? $reminder->payload : [];
                $attempts = (int) ($payload['dispatch_attempts'] ?? 0) + 1;
                $maxAttempts = max(1, (int) config('reminders.dispatch.max_attempts', 3));
                $retryDelayMinutes = max(1, (int) config('reminders.dispatch.retry_delay_minutes', 5));

                $payload['dispatch_attempts'] = $attempts;
                $payload['last_attempt_at'] = $now->toIso8601String();
                $payload['last_error'] = $exception->getMessage();
                $reminder->payload = $payload;

                if ($attempts >= $maxAttempts) {
                    $reminder->status = ReminderStatus::Cancelled;
                    $reminder->cancelled_at = $now;
                } else {
                    $reminder->snoozed_until = $now->toImmutable()->addMinutes($retryDelayMinutes);
                }

                $reminder->save();

                return false;
            }

            $reminder->status = ReminderStatus::Sent;
            $reminder->sent_at = $now;
            $reminder->save();

            return true;
        });
    }

    private function buildNotification(Reminder $reminder): ?object
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
