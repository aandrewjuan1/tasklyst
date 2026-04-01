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

        $numericIndices = $this->matchNumericIndices($normalizedMessage, $count);
        if ($numericIndices !== null) {
            return $this->sliceEntitiesByIndices($items, $numericIndices);
        }

        return [];
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
