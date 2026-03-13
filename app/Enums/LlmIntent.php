<?php

namespace App\Enums;

enum LlmIntent: string
{
    case Schedule = 'schedule';
    case Create = 'create';
    case Update = 'update';
    case Prioritize = 'prioritize';
    case List = 'list';
    case General = 'general';
    case Clarify = 'clarify';
    case Error = 'error';

    public function canTriggerToolCall(): bool
    {
        return in_array($this, [self::Schedule, self::Create, self::Update], true);
    }

    public function isReadOnly(): bool
    {
        return in_array($this, [self::Prioritize, self::List, self::General], true);
    }

    /**
     * @return array<int, string>
     */
    public static function allowedValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
