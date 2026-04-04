<?php

namespace App\Services\LLM\Intent;

/**
 * Shared normalization and "rank + schedule" hybrid cues for intent routing and signal scoring.
 */
final class TaskAssistantIntentHybridCue
{
    /**
     * Light normalization for heuristic matching (slang, common typos). Input should already be lowercased.
     */
    public static function normalizeForSignals(string $normalized): string
    {
        $normalized = trim($normalized);
        if ($normalized === '') {
            return '';
        }

        $replacements = [
            '/\btmrw\b/u' => 'tomorrow',
            '/\btommor?w\b/u' => 'tomorrow',
            '/\btonite\b/u' => 'tonight',
            '/\b2mrw\b/u' => 'tomorrow',
            '/\bevning\b/u' => 'evening',
            '/\bmornig\b/u' => 'morning',
            '/\bafternon\b/u' => 'afternoon',
            '/\b(pls|plz)\b/u' => 'please',
            '/\bgonna\b/u' => 'going to',
            '/\bwanna\b/u' => 'want to',
            '/\bgotta\b/u' => 'got to',
            '/\brn\b/u' => 'right now',
            '/\bidk\b/u' => 'i do not know',
            '/\bthru\b/u' => 'through',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $normalized = (string) preg_replace($pattern, $replacement, $normalized);
        }

        return $normalized;
    }

    /**
     * True when the message looks like "prioritize/rank top items" plus a scheduling/time cue.
     * Does not check refinement-draft context; callers must exclude refinement edits separately.
     */
    public static function matchesCombinedPrioritizeSchedulePrompt(string $normalized): bool
    {
        $mentionsTaskWords = preg_match('/\b(tasks?|task|to\s+do|todo)\b/u', $normalized) === 1;
        $mentionsNonTaskEntity = preg_match('/\b(events?|event|projects?|project)\b/u', $normalized) === 1;

        if ($mentionsNonTaskEntity && ! $mentionsTaskWords) {
            return false;
        }

        $hasPrioritizeCue =
            preg_match('/\b(my\s+)?(top|first|next)\b.*\b(tasks?|items?|task|item)\b/u', $normalized) === 1
            || preg_match('/\b(my\s+)?(top|first|next)\b\s+\d+\b/u', $normalized) === 1
            || preg_match('/\bwhat\s+.*\btop\s+tasks?\b/u', $normalized) === 1
            || preg_match('/\b(most|more)\s+important\b/u', $normalized) === 1
            || preg_match('/\bimportant\s+(tasks?|items?|work|stuff)\b/u', $normalized) === 1
            || preg_match('/\bwhat\s+matters\s+most\b/u', $normalized) === 1
            || preg_match('/\burgent(est)?\s+(tasks?|items?)\b/u', $normalized) === 1
            || preg_match('/\b(highest|high)\s+priority\b/u', $normalized) === 1
            || preg_match('/\bwhich\s+.*\bshould\s+i\s+do\b/u', $normalized) === 1;

        if (! $hasPrioritizeCue) {
            return false;
        }

        $hasSchedulingCue =
            preg_match('/\b(schedule|scheduling|calendar)\b/u', $normalized) === 1
            || preg_match('/\bplan\b/u', $normalized) === 1
            || preg_match('/\b(tomorrow|today|tonight|later|onward(s)?|morning|afternoon|evening|night)\b/u', $normalized) === 1
            || preg_match('/\b(when\s+(should|can|could|do)\s+i|what\s+time|where\s+(can|should)\s+i\s+(fit|put|squeeze))\b/u', $normalized) === 1
            || preg_match('/\b(time\s+slot|block\s+out|block\s+time)\b/u', $normalized) === 1
            || preg_match('/\b(fit|squeeze)\s+.{0,40}\bin\b/u', $normalized) === 1
            || preg_match('/\bperfect\s+time\b/u', $normalized) === 1
            || preg_match('/\b(at\s+\d{1,2})(?::\d{2})?\s*(am|pm)\b/iu', $normalized) === 1;

        return $hasSchedulingCue;
    }

    /**
     * Hybrid likelihood in [0, 1] for merge/signal-only routing.
     *
     * @param  float  $minBothFloor  Minimum min(prioritization, scheduling) before non-pattern hybrid blends apply.
     */
    public static function scoreHybridSignal(
        string $normalized,
        float $prioritization,
        float $scheduling,
        float $minBothFloor = 0.18,
    ): float {
        if (self::matchesCombinedPrioritizeSchedulePrompt($normalized)) {
            return min(1.0, max($prioritization, $scheduling) * 0.55 + 0.38);
        }

        $min = min($prioritization, $scheduling);
        if ($min < $minBothFloor) {
            return 0.0;
        }

        $blend = $min * 1.05 + 0.12 * $prioritization * $scheduling;

        return min(1.0, $blend);
    }
}
