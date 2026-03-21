<?php

namespace App\Services\LLM\Intent;

final class IntentClassificationService
{
    private const SCHEDULE_LIKE_PATTERN = '/\b(schedule|scheduling|plan my day|plan the day|my day|today plan|day plan|daily plan|time block|time-block|time blocking|calendar|time slot|when should i work)\b/i';

    public function isScheduleLikeRequest(string $content): bool
    {
        $normalized = $this->normalizeContent($content);

        if ($normalized === '') {
            return false;
        }

        return preg_match(self::SCHEDULE_LIKE_PATTERN, $normalized) === 1;
    }

    private function normalizeContent(string $content): string
    {
        return mb_strtolower(trim($content));
    }
}
