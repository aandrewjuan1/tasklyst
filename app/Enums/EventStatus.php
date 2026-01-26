<?php

namespace App\Enums;

enum EventStatus: string
{
    case Scheduled = 'scheduled';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case Tentative = 'tentative';
    case Ongoing = 'ongoing';

    public function color(): string
    {
        return match ($this) {
            self::Scheduled => 'blue-500',
            self::Cancelled => 'red-500',
            self::Completed => 'green-500',
            self::Tentative => 'yellow-500',
            self::Ongoing => 'purple-500',
        };
    }
}
