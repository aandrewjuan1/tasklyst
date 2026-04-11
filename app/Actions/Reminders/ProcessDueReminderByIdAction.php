<?php

namespace App\Actions\Reminders;

use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\User;
use App\Services\UserNotificationBroadcastService;
use App\Support\Reminders\ReminderNotificationFactory;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class ProcessDueReminderByIdAction
{
    public function __construct(
        private ReminderNotificationFactory $reminderNotificationFactory,
        private UserNotificationBroadcastService $userNotificationBroadcastService,
    ) {}

    public function execute(int $reminderId, CarbonInterface $now): bool
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

            $notification = $this->reminderNotificationFactory->make($reminder);
            if ($notification === null) {
                $reminder->status = ReminderStatus::Cancelled;
                $reminder->cancelled_at = $now;
                $reminder->save();

                return false;
            }

            try {
                $user->notify($notification);
                $this->userNotificationBroadcastService->broadcastInboxUpdated($user);
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
}
