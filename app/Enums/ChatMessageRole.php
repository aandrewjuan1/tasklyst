<?php

namespace App\Enums;

enum ChatMessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case System = 'system';
    case Tool = 'tool';
    case Meta = 'meta';

    public function isAssistantAuthored(): bool
    {
        return in_array($this, [self::Assistant, self::Tool, self::Meta], true);
    }
}
