<?php

namespace App\Enums;

enum TaskAssistantUserIntent: string
{
    case Prioritization = 'prioritization';
    case Scheduling = 'scheduling';
    case PrioritizeSchedule = 'prioritize_schedule';
    case GeneralGuidance = 'general_guidance';
    case OffTopic = 'off_topic';
    case Unclear = 'unclear';
    case Greeting = 'greeting';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
