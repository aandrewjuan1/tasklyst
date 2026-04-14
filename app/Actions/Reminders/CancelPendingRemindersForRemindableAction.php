<?php

namespace App\Actions\Reminders;

use App\Enums\ReminderStatus;
use App\Enums\ReminderType;
use App\Models\Reminder;
use Illuminate\Database\Eloquent\Model;

final class CancelPendingRemindersForRemindableAction
{
    public function execute(Model $model, ?ReminderType $type = null): void
    {
        $pendingQuery = Reminder::query()
            ->where('remindable_type', $model->getMorphClass())
            ->where('remindable_id', $model->getKey())
            ->where('status', ReminderStatus::Pending->value);

        if ($type !== null) {
            $pendingQuery->where('type', $type->value);
        }

        $pendingReminders = (clone $pendingQuery)->get([
            'id',
            'remindable_type',
            'remindable_id',
            'type',
            'scheduled_at',
        ]);

        foreach ($pendingReminders as $pendingReminder) {
            $hasCancelledDuplicate = Reminder::query()
                ->where('id', '<>', $pendingReminder->id)
                ->where('remindable_type', $pendingReminder->remindable_type)
                ->where('remindable_id', $pendingReminder->remindable_id)
                ->where('type', $pendingReminder->type)
                ->where('scheduled_at', $pendingReminder->scheduled_at)
                ->where('status', ReminderStatus::Cancelled->value)
                ->exists();

            if ($hasCancelledDuplicate) {
                Reminder::query()
                    ->whereKey($pendingReminder->id)
                    ->delete();
            }
        }

        $pendingQuery->update([
            'status' => ReminderStatus::Cancelled->value,
            'cancelled_at' => now(),
        ]);
    }
}
