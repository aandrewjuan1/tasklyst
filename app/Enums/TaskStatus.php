<?php

namespace App\Enums;

enum TaskStatus: string
{
    case ToDo = 'to_do';
    case Doing = 'doing';
    case Done = 'done';

    public function color(): string
    {
        return match ($this) {
            self::ToDo => 'gray-500',
            self::Doing => 'blue-500',
            self::Done => 'green-500',
        };
    }
}
