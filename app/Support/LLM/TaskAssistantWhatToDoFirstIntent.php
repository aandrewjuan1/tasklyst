<?php

namespace App\Support\LLM;

/**
 * Heuristic matcher for “what should I do first” / “top tasks … first” style prompts.
 * Used by {@see \App\Services\LLM\TaskAssistant\IntentRoutingPolicy} to short-circuit into the
 * prioritize (rank) flow — not a separate orchestration engine.
 *
 * Split into single-focus (one next step) vs multi-item (ordered slice) so routing and
 * count_limit (see {@see \App\Services\LLM\TaskAssistant\IntentRoutingPolicy::extractConstraintsForFlow()})
 * stay aligned. A bare “do first” substring is not sufficient to match (too many false positives).
 */
final class TaskAssistantWhatToDoFirstIntent
{
    public static function matches(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            return false;
        }

        return self::matchesSingleFocusPrioritizeFirst($normalized)
            || self::impliesMultiplePrioritizedRows($normalized)
            || self::matchesExplicitTopNumberDoFirst($normalized);
    }

    /**
     * e.g. "what's the top 3 that I should do first" — explicit N plus "do first", not the vague substring alone.
     */
    public static function matchesExplicitTopNumberDoFirst(string $normalized): bool
    {
        return (bool) preg_match('/\btop\s+\d+\b.{0,100}?\bdo\s+first\b/is', $normalized);
    }

    /**
     * Single next-step / first-in-line asks (typically count_limit 1).
     */
    public static function matchesSingleFocusPrioritizeFirst(string $normalized): bool
    {
        return (bool) preg_match(
            '/\b(?:what\s+should\s+i\s+do\s+first|what\s+task\s+should\s+i\s+do\s+first|which\s+task\s+should\s+i\s+do\s+first|what\s+should\s+i\s+work\s+on\s+first|where\s+should\s+i\s+start|what\s+do\s+i\s+start\s+with)\b/i',
            $normalized
        );
    }

    /**
     * User is asking for several ranked items (top tasks, which tasks … first), not only the single next action.
     * Used for count_limit defaults (see config task-assistant.intent.prioritize_default_multi_count).
     */
    public static function impliesMultiplePrioritizedRows(string $normalized): bool
    {
        $n = mb_strtolower(trim($normalized));
        if ($n === '') {
            return false;
        }

        if (preg_match('/\bwhat\s+top\s+tasks?\b/i', $n) === 1) {
            return true;
        }

        if (preg_match('/\b(?:what|which)\s+(?:are|were)\s+(?:the\s+)?top\s+tasks?\b/i', $n) === 1) {
            return true;
        }

        if (preg_match('/\b(?:which|what)\s+tasks\s+should\s+i\s+do\s+first\b/i', $n) === 1) {
            return true;
        }

        if (preg_match('/\btop\s+tasks?\b.{0,120}?\bdo\s+first\b/is', $n) === 1) {
            return true;
        }

        return false;
    }
}
