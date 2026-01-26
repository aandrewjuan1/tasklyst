<?php

namespace App\Enums;

enum EventRecurrenceType: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case Custom = 'custom';

    public function color(): string
    {
        return match ($this) {
            self::Daily => 'blue-500',
            self::Weekly => 'purple-500',
            self::Monthly => 'indigo-500',
            self::Yearly => 'pink-500',
            self::Custom => 'gray-500',
        };
    }
}
