<?php

namespace App\Enums;

enum TaskAssistantUserIntent: string
{
    case Prioritization = 'prioritization';
    case Scheduling = 'scheduling';
    case OffTopic = 'off_topic';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
