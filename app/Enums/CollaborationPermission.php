<?php

namespace App\Enums;

enum CollaborationPermission: string
{
    case View = 'view';
    case Edit = 'edit';

    public function label(): string
    {
        return match ($this) {
            self::View => 'View',
            self::Edit => 'Edit',
        };
    }
}
