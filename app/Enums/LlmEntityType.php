<?php

namespace App\Enums;

enum LlmEntityType: string
{
    case Task = 'task';
    case Event = 'event';
    case Project = 'project';
    case Multiple = 'multiple';

    public function label(): string
    {
        return match ($this) {
            self::Task => __('Task'),
            self::Event => __('Event'),
            self::Project => __('Project'),
            self::Multiple => __('Tasks and events'),
        };
    }
}
