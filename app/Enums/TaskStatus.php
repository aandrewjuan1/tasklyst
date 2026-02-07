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
            self::ToDo => 'gray-800',
            self::Doing => 'blue-800',
            self::Done => 'green-800',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ToDo => __('To Do'),
            self::Doing => __('Doing'),
            self::Done => __('Done'),
        };
    }
}
