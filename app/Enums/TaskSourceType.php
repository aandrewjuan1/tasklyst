<?php

namespace App\Enums;

enum TaskSourceType: string
{
    case Manual = 'manual';
    case Brightspace = 'brightspace';

    public function label(): string
    {
        return match ($this) {
            self::Manual => __('Manual'),
            self::Brightspace => __('Brightspace'),
        };
    }

    public function isExternal(): bool
    {
        return $this !== self::Manual;
    }
}
