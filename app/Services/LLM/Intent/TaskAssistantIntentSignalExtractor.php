<?php

namespace App\Services\LLM\Intent;

/**
 * Regex-based intent signals used to validate or override LLM route classification.
 *
 * Heuristic scores (0–1) for listing, prioritization, and scheduling intents.
 *
 * @phpstan-type IntentSignals array{listing: float, prioritization: float, scheduling: float}
 */
final class TaskAssistantIntentSignalExtractor
{
    private const SCHEDULE_LIKE_PATTERN = '/\b(schedule|scheduling|plan my day|plan the day|my day|today plan|day plan|daily plan|time block|time-block|time blocking|calendar|time slot|when should i work)\b/i';

    /**
     * @return IntentSignals
     */
    public function extract(string $normalized): array
    {
        $normalized = mb_strtolower(trim($normalized));

        return [
            'listing' => $this->scoreListing($normalized),
            'prioritization' => $this->scorePrioritization($normalized),
            'scheduling' => $this->scoreScheduling($normalized),
        ];
    }

    private function scoreListing(string $normalized): float
    {
        $score = 0.0;

        if (preg_match('/\b(list|show|display|find|search|filter|sort|which tasks?|what tasks?|give me|pull up)\b/i', $normalized) === 1) {
            $score += 0.55;
        }
        if (preg_match('/\b(due|tag|tags|status|priority|overdue|incomplete)\b/i', $normalized) === 1) {
            $score += 0.25;
        }
        if (preg_match('/\b(all|every|my tasks)\b/i', $normalized) === 1) {
            $score += 0.15;
        }

        return min(1.0, $score);
    }

    private function scorePrioritization(string $normalized): float
    {
        $score = 0.0;

        if (preg_match('/\b(top|priorit|first|next|important|focus|which|should i (do|work|start))\b/i', $normalized) === 1) {
            $score += 0.72;
        }
        if (preg_match('/\b(task|tasks)\b/i', $normalized) === 1) {
            $score += 0.15;
        }
        if (preg_match('/\b(\d+)\b/', $normalized) === 1) {
            $score += 0.08;
        }

        return min(1.0, $score);
    }

    private function scoreScheduling(string $normalized): float
    {
        $score = 0.0;

        if (preg_match(self::SCHEDULE_LIKE_PATTERN, $normalized) === 1) {
            $score += 0.72;
        }
        if (preg_match('/\b(afternoon|morning|evening|night|time block|time slot|later|today|tomorrow)\b/i', $normalized) === 1) {
            $score += 0.2;
        }
        if (preg_match('/\b(those|them|above)\b/i', $normalized) === 1) {
            $score += 0.1;
        }

        return min(1.0, $score);
    }
}
