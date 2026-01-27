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
            self::Daily => 'blue-800',
            self::Weekly => 'purple-800',
            self::Monthly => 'indigo-800',
            self::Yearly => 'pink-800',
            self::Custom => 'gray-800',
        };
    }
}
