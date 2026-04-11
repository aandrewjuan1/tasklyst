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
        $q = Reminder::query()
            ->where('remindable_type', $model->getMorphClass())
            ->where('remindable_id', $model->getKey())
            ->where('status', ReminderStatus::Pending->value);

        if ($type !== null) {
            $q->where('type', $type->value);
        }

        $q->update([
            'status' => ReminderStatus::Cancelled->value,
            'cancelled_at' => now(),
        ]);
    }
}
