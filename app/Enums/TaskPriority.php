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
            self::Low => 'gray-800',
            self::Medium => 'yellow-800',
            self::High => 'orange-800',
            self::Urgent => 'red-800',
        };
    }
}
