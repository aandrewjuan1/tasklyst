<?php

namespace App\Enums;

enum TaskRecurrenceType: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function color(): string
    {
        return match ($this) {
            self::Daily => 'blue-500',
            self::Weekly => 'purple-500',
            self::Monthly => 'indigo-500',
        };
    }
}
