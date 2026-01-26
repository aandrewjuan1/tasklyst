<?php

namespace App\Enums;

enum TaskPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    public function color(): string
    {
        return match ($this) {
            self::Low => 'gray-400',
            self::Medium => 'yellow-500',
            self::High => 'orange-500',
            self::Urgent => 'red-500',
        };
    }
}
