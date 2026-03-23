<?php

namespace App\Enums;

enum TaskAssistantUserIntent: string
{
    case Prioritization = 'prioritization';
    case Scheduling = 'scheduling';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
