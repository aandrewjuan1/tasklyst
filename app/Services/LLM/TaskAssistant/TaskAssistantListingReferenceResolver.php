<?php

namespace App\Services\LLM\TaskAssistant;

/**
 * Maps schedule-oriented phrases to concrete entity targets from {@see TaskAssistantConversationStateService::lastListing}.
 * This is multiturn reference resolution only (e.g. "schedule those") — not a separate browse/listing LLM flow.
 *
 * Precedence when multiple patterns match: explicit "last|bottom N" or "top|first N" wins over vague "those|them|the above"
 * (evaluated in that order: last/first slices, then those/them).
 */
final class TaskAssistantListingReferenceResolver
{
    /**
     * @return array<string, int>
     */
    private function numberWordsMap(): array
    {
        // Keep small for guardrail-friendly parsing.
        return [
            'one' => 1,
            'two' => 2,
            'three' => 3,
            'four' => 4,
            'five' => 5,
            'six' => 6,
            'seven' => 7,
            'eight' => 8,
            'nine' => 9,
            'ten' => 10,
        ];
    }

    private function parseCountToken(string $token): ?int
    {
        $t = mb_strtolower(trim($token));
        if ($t === '') {
            return null;
        }

        if (preg_match('/^\d+$/u', $t) === 1) {
            return max(1, (int) $t);
        }

        $map = $this->numberWordsMap();
        if (array_key_exists($t, $map)) {
            return $map[$t];
        }

        return null;
    }

    /**
     * @param  array{
     *   source_flow?: string,
     *   items: list<array{entity_type: string, entity_id: int, title: string, position: int}>,
     *   assistant_message_id?: int|null,
     *   last_limit?: int|null
     * }|null  $lastListing
     * @return array<int, array{entity_type: string, entity_id: int, title: string}>
     */
    public function resolveForSchedule(string $normalizedMessage, ?array $lastListing, string $resolvedFlow): array
    {
        if ($resolvedFlow !== 'schedule' || $lastListing === null) {
            return [];
        }

        $items = $lastListing['items'] ?? [];
        if ($items === []) {
            return [];
        }

        $count = count($items);
        $explicitLast = $this->matchLastBottomCount($normalizedMessage);
        $explicitFirst = $this->matchTopFirstCount($normalizedMessage);

        if ($explicitLast !== null) {
            $n = min($explicitLast, $count);

            return $this->sliceEntities($items, $count - $n, $n);
        }

        if ($explicitFirst !== null) {
            $n = min($explicitFirst, $count);

            return $this->sliceEntities($items, 0, $n);
        }

        if ($this->matchesThoseThemAbove($normalizedMessage)) {
            $thoseN = $this->matchThoseCount($normalizedMessage);
            if ($thoseN !== null) {
                $n = min($thoseN, $count);

                return $this->sliceEntities($items, 0, $n);
            }

            return $this->toTargetEntities($items);
        }

        // Handle single-item ordinal phrasing:
        // - "schedule only the first one for later"
        // - "put last one in the evening"
        // - "schedule the first one task(s) for later"
        $ordinalOne = $this->matchOrdinalOneFromPrompt($normalizedMessage);
        if ($ordinalOne !== null) {
            ['n' => $typedN, 'entity_type' => $typedEntityType, 'from_end' => $fromEnd] = $ordinalOne;
            $filtered = $typedEntityType !== null
                ? array_values(array_filter(
                    $items,
                    static fn (array $i): bool => (string) ($i['entity_type'] ?? '') === $typedEntityType
                ))
                : $items;

            if ($filtered !== []) {
                $n = min($typedN, count($filtered));
                if ($n > 0) {
                    $slice = $fromEnd
                        ? array_slice($filtered, count($filtered) - $n, $n)
                        : array_slice($filtered, 0, $n);

                    return $this->toTargetEntities($slice);
                }
            }
        }

        // Handle explicit ordinal positions like:
        // - "schedule second for tomorrow"
        // - "schedule second task for later"
        // - "put third tasks in the evening"
        $ordinalPosition = $this->matchOrdinalPositionSelectionFromPrompt($normalizedMessage);
        if ($ordinalPosition !== null) {
            ['index' => $idx, 'entity_type' => $typedEntityType] = $ordinalPosition;
            $filtered = $typedEntityType !== null
                ? array_values(array_filter(
                    $items,
                    static fn (array $i): bool => (string) ($i['entity_type'] ?? '') === $typedEntityType
                ))
                : $items;

            $filteredCount = count($filtered);
            if ($filteredCount > 0) {
                if ($idx === -1) {
                    $slice = [$filtered[$filteredCount - 1]];

                    return $this->toTargetEntities($slice);
                }

                if ($idx >= 0 && $idx < $filteredCount) {
                    $slice = [$filtered[$idx]];

                    return $this->toTargetEntities($slice);
                }
            }
        }

        // Handle explicit typed counts like:
        // - "schedule the two tasks for later"
        // - "put last 2 events in the evening"
        // This is multiturn reference resolution against the prior ordered listing.
        $typedCount = $this->matchTypedCountFromPrompt($normalizedMessage);
        if ($typedCount !== null) {
            ['n' => $typedN, 'entity_type' => $typedEntityType, 'from_end' => $fromEnd] = $typedCount;
            if ($typedN <= 0) {
                return [];
            }

            if ($typedEntityType !== null) {
                $filtered = array_values(array_filter(
                    $items,
                    static fn (array $i): bool => (string) ($i['entity_type'] ?? '') === $typedEntityType
                ));
            } else {
                $filtered = $items;
            }

            if ($filtered === []) {
                return [];
            }

            $n = min($typedN, count($filtered));
            if ($n <= 0) {
                return [];
            }

            if ($fromEnd) {
                $slice = array_slice($filtered, count($filtered) - $n, $n);
            } else {
                $slice = array_slice($filtered, 0, $n);
            }

            return $this->toTargetEntities($slice);
        }

        $numericIndices = $this->matchNumericIndices($normalizedMessage, $count);
        if ($numericIndices !== null) {
            return $this->sliceEntitiesByIndices($items, $numericIndices);
        }

        return [];
    }

    /**
     * @return array{n: int, entity_type: string|null, from_end: bool}|null
     */
    private function matchOrdinalOneFromPrompt(string $normalized): ?array
    {
        // This is intentionally narrow to avoid confusing times/indices:
        // we only match if the prompt contains one of the scheduling verbs.
        if (! preg_match('/\b(schedule|put|plan)\b/iu', $normalized)) {
            return null;
        }

        if (preg_match('/\b(only\s+)?(?:the\s+)?(first|top|last|bottom)\b.{0,40}?\b(one|ones)\b/iu', $normalized, $m) !== 1) {
            return null;
        }

        $ordinal = mb_strtolower((string) ($m[2] ?? ''));
        $fromEnd = in_array($ordinal, ['last', 'bottom'], true);

        $entityType = match (true) {
            preg_match('/\btask(s)?\b/iu', $normalized) === 1 => 'task',
            preg_match('/\bevent(s)?\b/iu', $normalized) === 1 => 'event',
            preg_match('/\bproject(s)?\b/iu', $normalized) === 1 => 'project',
            default => null,
        };

        return [
            'n' => 1,
            'entity_type' => $entityType,
            'from_end' => $fromEnd,
        ];
    }

    /**
     * @return array{index: int, entity_type: string|null}|null
     */
    private function matchOrdinalPositionSelectionFromPrompt(string $normalized): ?array
    {
        // Only interpret if it looks like a scheduling action.
        if (preg_match('/\b(schedule|put|plan)\b/iu', $normalized) !== 1) {
            return null;
        }

        // Avoid "2 pm" confusion by only using ordinal words.
        if (preg_match(
            '/\b(?:only\s+)?(?:the\s+)?(first|second|third|fourth|fifth|sixth|seventh|eighth|ninth|tenth|top|last|bottom)\b/iu',
            $normalized,
            $m
        ) !== 1) {
            return null;
        }

        $ordinalToken = mb_strtolower(trim((string) ($m[1] ?? '')));
        if ($ordinalToken === '') {
            return null;
        }

        $fromEnd = in_array($ordinalToken, ['last', 'bottom'], true);
        if ($ordinalToken === 'top') {
            $ordinalToken = 'first';
        }

        if ($fromEnd) {
            return ['index' => -1, 'entity_type' => $this->matchEntityTypeInPrompt($normalized)];
        }

        $frontMap = [
            'first' => 1,
            'second' => 2,
            'third' => 3,
            'fourth' => 4,
            'fifth' => 5,
            'sixth' => 6,
            'seventh' => 7,
            'eighth' => 8,
            'ninth' => 9,
            'tenth' => 10,
        ];

        $pos = $frontMap[$ordinalToken] ?? null;
        if ($pos === null) {
            return null;
        }

        return [
            'index' => (int) $pos - 1,
            'entity_type' => $this->matchEntityTypeInPrompt($normalized),
        ];
    }

    private function matchEntityTypeInPrompt(string $normalized): ?string
    {
        return match (true) {
            preg_match('/\btask(s)?\b/iu', $normalized) === 1 => 'task',
            preg_match('/\bevent(s)?\b/iu', $normalized) === 1 => 'event',
            preg_match('/\bproject(s)?\b/iu', $normalized) === 1 => 'project',
            default => null,
        };
    }

    /**
     * @return array{n: int, entity_type: string|null, from_end: bool}|null
     */
    private function matchTypedCountFromPrompt(string $normalized): ?array
    {
        // Require the count token to be directly adjacent to the type word,
        // e.g. "two tasks" / "2 events", to avoid confusing times like "2 pm".
        if (preg_match(
            '/\b(\d+|one|two|three|four|five|six|seven|eight|nine|ten)\b\s+(tasks?|task|events?|event|projects?|project|items?|item)\b/iu',
            $normalized,
            $m
        ) !== 1) {
            return null;
        }

        $n = $this->parseCountToken((string) ($m[1] ?? ''));
        if ($n === null) {
            return null;
        }

        $typeWord = mb_strtolower((string) ($m[2] ?? ''));

        $entityType = match (true) {
            preg_match('/\btask(s)?\b/iu', $typeWord) === 1 => 'task',
            preg_match('/\bevent(s)?\b/iu', $typeWord) === 1 => 'event',
            preg_match('/\bproject(s)?\b/iu', $typeWord) === 1 => 'project',
            preg_match('/\bitem(s)?\b/iu', $typeWord) === 1 => null,
            default => null,
        };

        $fromEnd = preg_match('/\b(last|bottom)\b/iu', $normalized) === 1;

        return [
            'n' => $n,
            'entity_type' => $entityType,
            'from_end' => $fromEnd,
        ];
    }

    /**
     * @param  list<array{entity_type: string, entity_id: int, title: string, position: int}>  $items
     * @return array<int, array{entity_type: string, entity_id: int, title: string}>
     */
    private function toTargetEntities(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[] = [
                'entity_type' => $item['entity_type'],
                'entity_id' => $item['entity_id'],
                'title' => $item['title'],
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{entity_type: string, entity_id: int, title: string, position: int}>  $items
     * @return array<int, array{entity_type: string, entity_id: int, title: string}>
     */
    private function sliceEntities(array $items, int $offset, int $length): array
    {
        $slice = array_slice($items, $offset, $length);

        return $this->toTargetEntities($slice);
    }

    private function matchesThoseThemAbove(string $normalized): bool
    {
        return preg_match('/\b(those|them|the\s+above)\b/i', $normalized) === 1;
    }

    private function matchThoseCount(string $normalized): ?int
    {
        if (preg_match('/\b(those|them)\s+(\d+)\b/', $normalized, $matches) === 1) {
            return max(1, min((int) ($matches[2] ?? 3), 10));
        }

        return null;
    }

    private function matchTopFirstCount(string $normalized): ?int
    {
        if (preg_match('/\b(top|first)\s+(\d+)\b/', $normalized, $matches) === 1) {
            return max(1, min((int) ($matches[2] ?? 3), 10));
        }

        if (preg_match('/\b(top|first)\s+(task|item)\b/i', $normalized) === 1) {
            return 1;
        }

        return null;
    }

    private function matchLastBottomCount(string $normalized): ?int
    {
        if (preg_match('/\b(last|bottom)\s+(\d+)\b/', $normalized, $matches) === 1) {
            return max(1, min((int) ($matches[2] ?? 3), 10));
        }

        return null;
    }

    /**
     * Interpret explicit numeric index selections (1-based) like:
     * - "schedule 1 and 2 for later afternoon"
     * - "schedule #1, #3 for tomorrow"
     *
     * Guardrails:
     * - Avoid interpreting "3pm"/times as indices by requiring an "and"/","/ "or" separator between numbers.
     * - Requires at least 2 indices to reduce false positives.
     *
     * @return list<int>|null
     */
    private function matchNumericIndices(string $normalized, int $count): ?array
    {
        if ($count < 1) {
            return null;
        }

        // Require a separator ("and/or", comma) between numbers to avoid grabbing times.
        if (preg_match('/(?:#\s*)?\d+\s*(?:,|and|or)\s*(?:#\s*)?\d+/i', $normalized) !== 1) {
            return null;
        }

        if (! preg_match('/\b(schedule|task|tasks)\b/i', $normalized) && ! preg_match('/\bfor\b/i', $normalized)) {
            return null;
        }

        if (! preg_match_all('/(?:#\s*)?(\d+)\b/', $normalized, $matches)) {
            return null;
        }

        $raw = [];
        foreach ($matches[1] as $m) {
            $n = (int) $m;
            if ($n < 1 || $n > $count) {
                continue;
            }
            $raw[] = $n;
        }

        if ($raw === []) {
            return null;
        }

        $unique = [];
        foreach ($raw as $n) {
            if (! in_array($n, $unique, true)) {
                $unique[] = $n;
            }
        }

        return count($unique) >= 2 ? $unique : null;
    }

    /**
     * Slice listing items by 1-based positions.
     *
     * @param  list<array{entity_type: string, entity_id: int, title: string, position: int}>  $items
     * @param  list<int>  $indices  1-based positions
     * @return array<int, array{entity_type: string, entity_id: int, title: string}>
     */
    private function sliceEntitiesByIndices(array $items, array $indices): array
    {
        $out = [];

        foreach ($indices as $index) {
            $pos = (int) $index - 1;
            $item = $items[$pos] ?? null;
            if (! is_array($item)) {
                continue;
            }

            $out[] = [
                'entity_type' => (string) ($item['entity_type'] ?? ''),
                'entity_id' => (int) ($item['entity_id'] ?? 0),
                'title' => (string) ($item['title'] ?? ''),
            ];
        }

        return $out;
    }
}
