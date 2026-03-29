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

        return null;
    }

    private function matchLastBottomCount(string $normalized): ?int
    {
        if (preg_match('/\b(last|bottom)\s+(\d+)\b/', $normalized, $matches) === 1) {
            return max(1, min((int) ($matches[2] ?? 3), 10));
        }

        return null;
    }
}
