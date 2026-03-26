<?php

namespace App\Support\LLM;

/**
 * User-visible defaults for listing when data or the LLM omits optional fields.
 */
final class TaskAssistantListingDefaults
{
    public static function maxFramingChars(): int
    {
        // Must match TaskAssistantResponseProcessor::validatePrioritizeListingData().
        // framing max = min(400, maxSuggestedGuidanceChars()).
        return min(400, self::maxSuggestedGuidanceChars());
    }

    public static function clampFraming(string $text): string
    {
        $max = self::maxFramingChars();
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1).'…';
    }

    public static function maxReasoningChars(): int
    {
        $max = (int) config('task-assistant.listing.max_reasoning_chars', 800);

        return max(1, $max);
    }

    /**
     * Keeps listing payload reasoning within max length.
     */
    public static function clampBrowseReasoning(string $text): string
    {
        $max = self::maxReasoningChars();
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1).'…';
    }

    public static function maxSuggestedGuidanceChars(): int
    {
        $max = (int) config('task-assistant.listing.max_suggested_guidance_chars', 1200);

        return max(80, $max);
    }

    public static function maxNextFieldChars(): int
    {
        // Must match TaskAssistantResponseProcessor::validatePrioritizeListingData().
        // next_actions_intro + next_options max = min(260, maxReasoningChars()).
        return max(1, min(260, self::maxReasoningChars()));
    }

    public static function clampNextField(string $text): string
    {
        $max = self::maxNextFieldChars();
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1).'…';
    }

    public static function maxSuggestedNextActionChars(): int
    {
        // Must match TaskAssistantResponseProcessor::validatePrioritizeListingData().
        return 180;
    }

    public static function clampSuggestedNextAction(string $text): string
    {
        $max = self::maxSuggestedNextActionChars();
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1).'…';
    }

    public static function maxNextOptionChipTextChars(): int
    {
        // Must match TaskAssistantResponseProcessor::validatePrioritizeListingData().
        return 120;
    }

    public static function clampNextOptionChipText(string $text): string
    {
        $max = self::maxNextOptionChipTextChars();
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1).'…';
    }

    /**
     * Enforce student-directed POV for prioritize `reasoning`.
     *
     * Your formatter expects `reasoning` to help the student directly (and
     * not use third-person descriptions like "they match the user's...").
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public static function normalizePrioritizeReasoningVoice(string $reasoning, array $items): string
    {
        $trimmed = trim($reasoning);
        if ($trimmed === '') {
            return $trimmed;
        }

        // Allow first/second person; rewrite when third-person patterns appear.
        $startsWithAllowedPronoun = (bool) preg_match('/^(I|You)\b/u', $trimmed);
        $hasThirdPersonLeak = (bool) preg_match(
            '/\b(the\s+user|the\s+user\'s|user\'s\s+current|they\s+match|this\s+list\s+matches)\b/i',
            $trimmed
        );

        if ($startsWithAllowedPronoun && ! $hasThirdPersonLeak && ! self::reasoningConflictsWithItems($trimmed, $items)) {
            return $trimmed;
        }

        $first = null;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (strtolower(trim((string) ($item['entity_type'] ?? 'task'))) === 'task') {
                $first = $item;
                break;
            }
        }

        if ($first === null && isset($items[0]) && is_array($items[0])) {
            $first = $items[0];
        }

        $title = $first !== null ? trim((string) ($first['title'] ?? '')) : '';
        $duePhrase = $first !== null ? trim((string) ($first['due_phrase'] ?? '')) : '';
        $priorityRaw = $first !== null ? trim((string) ($first['priority'] ?? '')) : '';
        $priorityLabel = $priorityRaw !== '' ? ucfirst(strtolower($priorityRaw)) : '';

        $single = count($items) === 1;
        $out = $single
            ? 'I chose this task because it helps you get started with one manageable next step'
            : 'I chose these priorities because they help you get started with manageable next steps';

        if ($duePhrase !== '' && $duePhrase !== 'no due date') {
            $out .= $single ? ', and it is '.$duePhrase : ', and they are '.$duePhrase;
        }
        if ($priorityLabel !== '') {
            $out .= $single ? ', and it has '.$priorityLabel.' priority' : ', and they are '.$priorityLabel.' priority';
        }
        $out .= '.';

        // If we have a clear title, add a short actionable anchor sentence.
        if ($title !== '' && mb_strlen($out) < (self::maxReasoningChars() - 30)) {
            $out .= ' Start with '.$title.'.';
        }

        return self::clampBrowseReasoning(trim($out));
    }

    /**
     * Detect contradictions between narrative reasoning and listed item fields.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private static function reasoningConflictsWithItems(string $reasoning, array $items): bool
    {
        if ($items === []) {
            return false;
        }

        $lower = mb_strtolower($reasoning);

        $allowedDueTokens = [];
        $allowedPriorities = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $duePhrase = mb_strtolower(trim((string) ($item['due_phrase'] ?? '')));
            if ($duePhrase !== '') {
                $allowedDueTokens[] = $duePhrase;
            }

            $priority = mb_strtolower(trim((string) ($item['priority'] ?? '')));
            if ($priority !== '') {
                $allowedPriorities[] = $priority;
            }
        }

        $allowedDueTokens = array_values(array_unique($allowedDueTokens));
        $allowedPriorities = array_values(array_unique($allowedPriorities));

        // Due phrase tokens we allow mentioning only if present in items.
        $duePhraseTokens = [
            'due tomorrow',
            'due today',
            'due yesterday',
            'due this week',
            'overdue',
        ];
        $allowedDueLower = array_map(static fn (string $p): string => mb_strtolower($p), $allowedDueTokens);

        foreach ($duePhraseTokens as $token) {
            if (mb_stripos($lower, $token) === false) {
                continue;
            }

            if (! in_array($token, $allowedDueLower, true)) {
                return true;
            }
        }

        // Priority drift: only mention priority labels that exist on the items.
        $priorityTokens = ['high', 'medium', 'low'];
        foreach ($priorityTokens as $token) {
            if (mb_stripos($lower, $token.' priority') === false) {
                continue;
            }

            if ($allowedPriorities !== [] && ! in_array($token, $allowedPriorities, true)) {
                return true;
            }
        }

        return false;
    }

    public static function clampBrowseSuggestedGuidance(string $text): string
    {
        $max = self::maxSuggestedGuidanceChars();
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1).'…';
    }

    public static function maxItemPlacementBlurbChars(): int
    {
        $max = (int) config('task-assistant.listing.max_item_placement_blurb_chars', 200);

        return max(40, $max);
    }

    public static function clampItemPlacementBlurb(string $text): string
    {
        $max = self::maxItemPlacementBlurbChars();
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1).'…';
    }

    public static function defaultSuggestedGuidance(): string
    {
        return __('I suggest picking one task from the list to start with so you don\'t get overwhelmed. If you tell me your focus, I can help you narrow this list or plan what to tackle first.');
    }

    public static function reasoningWhenEmpty(): string
    {
        return __('This list reflects your filters and the same ranking order used elsewhere in the assistant.');
    }

    public static function complexityNotSetLabel(): string
    {
        return __('Not set');
    }

    public static function noDueDateLabel(): string
    {
        return __('No due date');
    }
}
