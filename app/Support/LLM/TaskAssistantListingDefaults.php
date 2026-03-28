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
        $max = (int) config('task-assistant.listing.max_framing_chars', 900);

        return max(80, min($max, self::maxSuggestedGuidanceChars()));
    }

    public static function clampFraming(string $text): string
    {
        $max = self::maxFramingChars();
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1).'…';
    }

    /**
     * When exactly one prioritized row is returned, fix common plural slips in model copy (framing, reasoning, etc.).
     * Uses the first row's entity_type for task/event/project nouns.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public static function coerceSingularPrioritizeNarrative(string $text, int $listedItemCount, array $items = []): string
    {
        if ($listedItemCount !== 1) {
            return $text;
        }

        if (trim($text) === '') {
            return $text;
        }

        $first = isset($items[0]) && is_array($items[0]) ? $items[0] : [];
        $entity = strtolower(trim((string) ($first['entity_type'] ?? 'task')));
        if (! in_array($entity, ['task', 'event', 'project'], true)) {
            $entity = 'task';
        }

        $singular = match ($entity) {
            'event' => 'event',
            'project' => 'project',
            default => 'task',
        };

        $plural = match ($entity) {
            'event' => 'events',
            'project' => 'projects',
            default => 'tasks',
        };

        $out = $text;

        $swap = static function (string $s, array $pairs): string {
            foreach ($pairs as [$from, $to]) {
                $s = str_replace($from, $to, $s);
            }

            return $s;
        };

        $out = $swap($out, [
            ['These top priorities', 'This top priority'],
            ['these top priorities', 'this top priority'],
            ['Those top priorities', 'That top priority'],
            ['those top priorities', 'that top priority'],
            ['These priorities', 'This priority'],
            ['these priorities', 'this priority'],
            ['Those priorities', 'That priority'],
            ['those priorities', 'that priority'],
            ['top priorities first', 'top priority first'],
        ]);

        $out = $swap($out, [
            ['These high-priority '.$plural, 'This high-priority '.$singular],
            ['these high-priority '.$plural, 'this high-priority '.$singular],
            ['Those high-priority '.$plural, 'That high-priority '.$singular],
            ['those high-priority '.$plural, 'that high-priority '.$singular],
            ['High-priority '.$plural.' that are', 'High-priority '.$singular.' that is'],
            ['high-priority '.$plural.' that are', 'high-priority '.$singular.' that is'],
            ['These '.$plural, 'This '.$singular],
            ['these '.$plural, 'this '.$singular],
            ['Those '.$plural, 'That '.$singular],
            ['those '.$plural, 'that '.$singular],
            ['High-priority '.$plural, 'High-priority '.$singular],
            ['high-priority '.$plural, 'high-priority '.$singular],
        ]);

        $out = $swap($out, [
            ['High-priority '.$singular.' that are', 'High-priority '.$singular.' that is'],
            ['high-priority '.$singular.' that are', 'high-priority '.$singular.' that is'],
            ['This '.$singular.' are ', 'This '.$singular.' is '],
            ['this '.$singular.' are ', 'this '.$singular.' is '],
            ['That '.$singular.' are ', 'That '.$singular.' is '],
            ['that '.$singular.' are ', 'that '.$singular.' is '],
        ]);

        $out = $swap($out, [
            ['They\'re already overdue', 'It\'s already overdue'],
            ['they\'re already overdue', 'it\'s already overdue'],
            ['They are already overdue', 'It is already overdue'],
            ['they are already overdue', 'it is already overdue'],
            ['By tackling them', 'By tackling it'],
            ['by tackling them', 'by tackling it'],
            ['Tackling them', 'Tackling it'],
            ['tackling them', 'tackling it'],
            ['even though they have', 'even though it has'],
            ['Even though they have', 'Even though it has'],
        ]);

        $out = $swap($out, [
            ['They have ', 'It has '],
            ['they have ', 'it has '],
            ['They are ', 'It is '],
            ['they are ', 'it is '],
        ]);

        return $out;
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

        $hasThirdPersonLeak = (bool) preg_match(
            '/\b(the\s+user|the\s+user\'s|user\'s\s+current|they\s+match|this\s+list\s+matches)\b/i',
            $trimmed
        );

        // Keep grounded model copy whenever voice is fine and due/priority tokens match items.
        // (Do not require "I/You" at the start—Let's, We, This, Here, etc. are valid assistant voice.)
        if (! $hasThirdPersonLeak && ! self::reasoningConflictsWithItems($trimmed, $items)) {
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
        $priorityTokens = ['high', 'medium', 'low', 'urgent'];
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

    /**
     * Strip legacy prioritize reasoning tail appended by older assistant versions (robotic anchor line).
     */
    public static function stripRoboticPrioritizeReasoningTail(string $reasoning): string
    {
        $pattern = '/\R{2,}Start with .+? when you[\x{2019}\']re ready—it[\x{2019}\']s first on this ordered list\./us';
        $out = preg_replace($pattern, '', $reasoning);

        return trim(is_string($out) ? $out : $reasoning);
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
