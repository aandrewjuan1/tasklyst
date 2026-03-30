<?php

namespace App\Services\LLM\Scheduling;

/**
 * Deterministically splits long task work into focus-sized minutes for scheduling.
 */
final class ScheduleTaskChunkingService
{
    /**
     * @return list<int>
     */
    public function chunkTaskMinutes(int $totalMinutes): array
    {
        if ($totalMinutes <= 0) {
            return [];
        }

        $cfg = config('task-assistant.schedule.chunking', []);
        $cfg = is_array($cfg) ? $cfg : [];

        $maxFocus = max(1, (int) ($cfg['max_focus_minutes'] ?? 90));
        $minChunk = max(1, (int) ($cfg['min_chunk_minutes'] ?? 15));

        $preferred = $cfg['preferred_chunk_sizes'] ?? [90, 60, 45, 30, 25];
        if (! is_array($preferred)) {
            $preferred = [90, 60, 45, 30, 25];
        }

        /** @var list<int> $preferredInts */
        $preferredInts = array_values(array_filter(
            array_map(static fn (mixed $n): int => (int) $n, $preferred),
            static fn (int $n): bool => $n > 0
        ));
        rsort($preferredInts, SORT_NUMERIC);

        if ($totalMinutes <= $maxFocus) {
            return [$totalMinutes];
        }

        /** @var list<int> $chunks */
        $chunks = [];
        $remaining = $totalMinutes;

        while ($remaining > 0) {
            if ($remaining <= $maxFocus) {
                if ($remaining < $minChunk && $chunks !== []) {
                    $lastKey = array_key_last($chunks);
                    $chunks[$lastKey] += $remaining;
                } else {
                    $chunks[] = $remaining;
                }
                break;
            }

            $pick = $maxFocus;
            foreach ($preferredInts as $p) {
                if ($p <= $maxFocus && $p <= $remaining) {
                    $pick = $p;

                    break;
                }
            }

            $chunks[] = $pick;
            $remaining -= $pick;
        }

        return $chunks;
    }
}
