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
            self::Scheduled => 'blue-800',
            self::Cancelled => 'red-800',
            self::Completed => 'green-800',
            self::Tentative => 'yellow-800',
            self::Ongoing => 'purple-800',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => __('Scheduled'),
            self::Cancelled => __('Cancelled'),
            self::Completed => __('Completed'),
            self::Tentative => __('Tentative'),
            self::Ongoing => __('Ongoing'),
        };
    }
}
