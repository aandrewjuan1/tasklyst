<?php

namespace App\Services\LLM\TaskAssistant;

final class TaskAssistantDeterministicReplyQualityService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{data: array<string, mixed>, corrections: array<string, mixed>}
     */
    public function normalize(string $flow, array $data): array
    {
        return match ($flow) {
            'prioritize' => $this->normalizePrioritize($data),
            'daily_schedule' => $this->normalizeDailySchedule($data),
            'general_guidance' => $this->normalizeGeneralGuidance($data),
            default => ['data' => $data, 'corrections' => []],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{data: array<string, mixed>, corrections: array<string, mixed>}
     */
    private function normalizePrioritize(array $data): array
    {
        $corrections = [];

        $ordering = is_array($data['ordering_rationale'] ?? null) ? $data['ordering_rationale'] : [];
        if ($ordering !== []) {
            $rewritten = [];
            $stems = [
                'This rises because',
                'This stays high because',
                'This belongs near the top because',
                'This is worth doing soon because',
            ];
            foreach ($ordering as $index => $line) {
                $text = trim((string) $line);
                if ($text === '') {
                    continue;
                }

                $text = preg_replace('/\bThis task stands out because\b/iu', $stems[$index % count($stems)], $text) ?? $text;
                $rewritten[] = $text;
            }

            if ($rewritten !== $ordering) {
                $data['ordering_rationale'] = $rewritten;
                $corrections['prioritize_ordering_varied_stems'] = true;
            }
        }

        $reasoning = trim((string) ($data['reasoning'] ?? ''));
        if ($reasoning !== '') {
            $cleaned = $this->normalizeWhitespace($reasoning);
            $cleaned = preg_replace('/\b(manageable effort)\b/iu', 'a manageable workload', $cleaned) ?? $cleaned;
            if ($cleaned !== $reasoning) {
                $data['reasoning'] = $cleaned;
                $corrections['prioritize_reasoning_tone_polished'] = true;
            }
        }

        return ['data' => $data, 'corrections' => $corrections];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{data: array<string, mixed>, corrections: array<string, mixed>}
     */
    private function normalizeDailySchedule(array $data): array
    {
        $corrections = [];
        $narrativeFacts = is_array($data['narrative_facts'] ?? null) ? $data['narrative_facts'] : [];
        $requestedHorizon = mb_strtolower(trim((string) ($narrativeFacts['requested_horizon_label'] ?? '')));

        ['items' => $items, 'blocks' => $blocks, 'proposals' => $proposals, 'sorted' => $sorted] = $this->sortScheduleRowsChronologically($data);
        if ($sorted) {
            $data['items'] = $items;
            $data['blocks'] = $blocks;
            if (array_key_exists('proposals', $data)) {
                $data['proposals'] = $proposals;
            }
            $corrections['schedule_rows_sorted_chronologically'] = true;
        }

        $scheduleRows = is_array($data['items'] ?? null) ? array_values($data['items']) : [];
        foreach (['framing', 'reasoning', 'confirmation'] as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value === '') {
                continue;
            }

            $updated = $this->normalizeWhitespace($value);
            $updated = preg_replace('/\binto in\b/iu', 'into', $updated) ?? $updated;
            $updated = preg_replace('/\bthe in your\b/iu', 'your', $updated) ?? $updated;
            $updated = preg_replace('/\bbefore you save\b/iu', 'before we lock it in', $updated) ?? $updated;
            $updated = preg_replace('/\b2-block run\b/iu', 'two focused blocks', $updated) ?? $updated;
            $updated = preg_replace('/\bearliest realistic windows\b/iu', 'time that fits your availability', $updated) ?? $updated;

            if (! $this->isMultiDay($items)) {
                $updated = preg_replace('/\bI spread placements across\b[^.?!]*[.?!]?\s*/iu', '', $updated) ?? $updated;
            }

            if ($this->containsHardBlockedSchedulePhrases($updated)) {
                $updated = $this->rewriteScheduleNarrativeForMentorTone($field, $scheduleRows, $updated);
                $corrections['schedule_'.$field.'_hard_block_rewrite'] = true;
            }

            if ($field === 'reasoning' && $this->hasOrderingContradiction($updated, $items)) {
                $updated = 'This plan follows your latest change and keeps your workload realistic in the time window you asked for.';
            }

            if (($requestedHorizon === 'tomorrow' || $requestedHorizon === 'tonight') && preg_match('/\b(today|tonight)\b/iu', $updated)) {
                $updated = preg_replace('/\b(today|tonight)\b/iu', 'tomorrow', $updated) ?? $updated;
                $corrections['schedule_'.$field.'_relative_day_aligned'] = true;
            }
            if ($requestedHorizon === 'today' && preg_match('/\btomorrow\b/iu', $updated)) {
                $updated = preg_replace('/\btomorrow\b/iu', 'today', $updated) ?? $updated;
                $corrections['schedule_'.$field.'_relative_day_aligned'] = true;
            }

            if ($updated !== $value) {
                $data[$field] = trim($updated);
                $corrections['schedule_'.$field.'_normalized'] = true;
            }
        }

        return ['data' => $data, 'corrections' => $corrections];
    }

    /**
     * @param  array<int, mixed>  $rows
     */
    private function rewriteScheduleNarrativeForMentorTone(string $field, array $rows, string $fallback): string
    {
        $count = count($rows);
        if ($count <= 1) {
            $singleTitle = trim((string) data_get($rows, '0.title', 'this task'));
            if ($singleTitle === '') {
                $singleTitle = 'this task';
            }
            $singleTime = $this->formatTimeRangeLabel(
                (string) data_get($rows, '0.start_datetime', ''),
                (string) data_get($rows, '0.end_datetime', '')
            );

            return match ($field) {
                'framing' => $singleTime !== ''
                    ? "I set {$singleTitle} in a clear slot at {$singleTime} so you can start without overthinking."
                    : "I set {$singleTitle} in a clear slot so you can start without overthinking.",
                'reasoning' => 'This timing keeps your plan realistic for today and gives you one focused step to complete.',
                'confirmation' => 'If you want a different time, tell me what works better and I will adjust it.',
                default => $fallback,
            };
        }

        return match ($field) {
            'framing' => 'I mapped these blocks in a practical order so the plan stays realistic and easier to follow.',
            'reasoning' => 'This sequence matches your available windows and keeps each block focused instead of overloaded.',
            'confirmation' => 'If you want changes, tell me which block to move or resize and I will update it.',
            default => $fallback,
        };
    }

    private function formatTimeRangeLabel(string $startRaw, string $endRaw): string
    {
        $start = strtotime($startRaw);
        $end = strtotime($endRaw);
        if ($start === false || $end === false) {
            return '';
        }

        return date('g:i A', $start).'–'.date('g:i A', $end);
    }

    private function containsHardBlockedSchedulePhrases(string $value): bool
    {
        $normalized = mb_strtolower($value);

        $blockedPatterns = [
            'earliest realistic windows',
            'biggest work starts first',
            'follow-up blocks stay lighter',
            'across your planned blocks between',
        ];
        foreach ($blockedPatterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{data: array<string, mixed>, corrections: array<string, mixed>}
     */
    private function normalizeGeneralGuidance(array $data): array
    {
        $corrections = [];
        foreach (['acknowledgement', 'message', 'next_options'] as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            $updated = $this->normalizeWhitespace($value);
            $updated = preg_replace('/\bI can assist\b/iu', 'I can help', $updated) ?? $updated;
            if ($updated !== $value) {
                $data[$field] = $updated;
                $corrections['general_guidance_'.$field.'_normalized'] = true;
            }
        }

        return ['data' => $data, 'corrections' => $corrections];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *   items: array<int, mixed>,
     *   blocks: array<int, mixed>,
     *   proposals: array<int, mixed>,
     *   sorted: bool
     * }
     */
    private function sortScheduleRowsChronologically(array $data): array
    {
        $items = is_array($data['items'] ?? null) ? array_values($data['items']) : [];
        $blocks = is_array($data['blocks'] ?? null) ? array_values($data['blocks']) : [];
        $proposals = is_array($data['proposals'] ?? null) ? array_values($data['proposals']) : [];
        $maxRows = min(count($items), count($blocks));
        if ($maxRows <= 1) {
            return [
                'items' => $items,
                'blocks' => $blocks,
                'proposals' => $proposals,
                'sorted' => false,
            ];
        }

        $rows = [];
        for ($index = 0; $index < $maxRows; $index++) {
            $item = is_array($items[$index] ?? null) ? $items[$index] : [];
            $start = trim((string) ($item['start_datetime'] ?? ''));
            $timestamp = $start !== '' ? strtotime($start) : false;
            $rows[] = [
                'index' => $index,
                'timestamp' => $timestamp === false ? PHP_INT_MAX : (int) $timestamp,
            ];
        }

        $original = array_map(static fn (array $row): int => $row['index'], $rows);
        usort($rows, static function (array $left, array $right): int {
            if ($left['timestamp'] === $right['timestamp']) {
                return $left['index'] <=> $right['index'];
            }

            return $left['timestamp'] <=> $right['timestamp'];
        });
        $sortedIndexes = array_map(static fn (array $row): int => $row['index'], $rows);
        if ($sortedIndexes === $original) {
            return [
                'items' => $items,
                'blocks' => $blocks,
                'proposals' => $proposals,
                'sorted' => false,
            ];
        }

        $sortedItems = [];
        $sortedBlocks = [];
        $sortedProposals = [];
        foreach ($sortedIndexes as $rowIndex) {
            $sortedItems[] = $items[$rowIndex];
            $sortedBlocks[] = $blocks[$rowIndex] ?? [];
            if (isset($proposals[$rowIndex])) {
                $sortedProposals[] = $proposals[$rowIndex];
            }
        }

        return [
            'items' => $sortedItems,
            'blocks' => $sortedBlocks,
            'proposals' => $sortedProposals,
            'sorted' => true,
        ];
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function isMultiDay(array $items): bool
    {
        $dates = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $start = trim((string) ($item['start_datetime'] ?? ''));
            if ($start === '') {
                continue;
            }
            $day = substr($start, 0, 10);
            if ($day !== '') {
                $dates[$day] = true;
            }
        }

        return count($dates) > 1;
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function hasOrderingContradiction(string $reasoning, array $items): bool
    {
        $value = mb_strtolower($reasoning);
        if (! str_contains($value, 'starts first') && ! str_contains($value, 'start first')) {
            return false;
        }

        if (count($items) < 2) {
            return false;
        }

        $firstStart = strtotime((string) data_get($items, '0.start_datetime', ''));
        $secondStart = strtotime((string) data_get($items, '1.start_datetime', ''));
        if ($firstStart === false || $secondStart === false) {
            return false;
        }

        return $firstStart > $secondStart;
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim((string) (preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
