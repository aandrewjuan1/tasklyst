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
            '/\btmr\b/u' => 'tomorrow',
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
            '/\basap\b/u' => 'as soon as possible',
            '/\bthru\b/u' => 'through',
            '/\bsort\s+out\b/u' => 'organize',
            '/\bmap\s+out\b/u' => 'plan',
            '/\bline\s+up\b/u' => 'plan',
            '/\bslot\s+in\b/u' => 'fit in',
            '/\bcalendar\s+this\b/u' => 'put on my calendar',
            '/\bfigure\s+out\s+what\s+to\s+do\b/u' => 'what should i do first',
            '/\bwhat\s+matters\s+rn\b/u' => 'what matters right now',
            '/\bwhat\s+should\s+i\s+hit\s+first\b/u' => 'what should i do first',
            '/\bknock\s+it\s+out\b/u' => 'tackle first',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $normalized = (string) preg_replace($pattern, $replacement, $normalized);
        }

        return $normalized;
    }

    /**
     * Calendar / time-blocking language strong enough to pair with prioritization for hybrid routing.
     * Excludes bare temporal words (e.g. "today") or standalone "plan" without "my day" / "the week".
     */
    public static function hasExplicitSchedulingLanguage(string $normalized): bool
    {
        if ($normalized === '') {
            return false;
        }

        return preg_match('/\b(schedule|scheduling|calendar|reschedule)\b/u', $normalized) === 1
            || preg_match('/\b(time[\s-]?slot|block\s+out|block\s+time|slot\s+in)\b/u', $normalized) === 1
            || preg_match('/\b(when\s+(should|can|could|do)\s+i|what\s+time|where\s+(can|should)\s+i\s+(fit|put|squeeze))\b/u', $normalized) === 1
            || preg_match('/\b(fit|squeeze)\s+.{0,40}\bin\b/u', $normalized) === 1
            || preg_match('/\b(put|place|set)\s+.{0,24}\bon\s+(my|the)\s+calendar\b/u', $normalized) === 1
            || preg_match('/\bperfect\s+time\b/u', $normalized) === 1
            || preg_match('/\b(at\s+\d{1,2})(?::\d{2})?\s*(am|pm)\b/iu', $normalized) === 1
            || preg_match('/\b\d{1,2}(?::\d{2})?\s*(am|pm)\b/iu', $normalized) === 1
            || preg_match('/\b(plan|organize|line\s+up|map\s+out)\b.{0,28}\b(my|the)\s+(whole\s+)?(day|week)\b/u', $normalized) === 1;
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
            || preg_match('/\bwhich\s+.*\bshould\s+i\s+do\b/u', $normalized) === 1
            || preg_match('/\b(what\s+should\s+i\s+tackle|which\s+one\s+first|tackle\s+first|what\s+do\s+i\s+do\s+first)\b/u', $normalized) === 1
            || preg_match('/\b(sort|rank|order)\b.{0,28}\b(tasks?|priorit(?:y|ies)|urgency)\b/u', $normalized) === 1;

        if (! $hasPrioritizeCue) {
            return false;
        }

        return self::hasExplicitSchedulingLanguage($normalized);
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
