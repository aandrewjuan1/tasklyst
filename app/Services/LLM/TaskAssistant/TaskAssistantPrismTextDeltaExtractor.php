<?php

namespace App\Services\LLM\TaskAssistant;

final class TaskAssistantPrismTextDeltaExtractor
{
    public function extractDelta(mixed $event): ?string
    {
        if (! is_object($event)) {
            return null;
        }

        $vars = get_object_vars($event);
        $delta = $vars['delta'] ?? null;

        return is_string($delta) && $delta !== '' ? $delta : null;
    }
}
