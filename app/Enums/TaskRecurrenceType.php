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
            self::Daily => 'blue-800',
            self::Weekly => 'purple-800',
            self::Monthly => 'indigo-800',
        };
    }
}
