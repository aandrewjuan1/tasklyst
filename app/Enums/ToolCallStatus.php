<?php

namespace App\Enums;

enum ToolCallStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}
