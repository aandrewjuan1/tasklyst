<?php

namespace App\Services\LLM\TaskAssistant;

/**
 * Single place to turn validated structured assistant payloads into the user-visible message body.
 * Used for prioritize, browse, and daily_schedule flows.
 */
final class TaskAssistantMessageFormatter
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $snapshot
     */
    public function format(string $flow, array $data, array $snapshot = []): string
    {
        $body = match ($flow) {
            'prioritize' => $this->formatPrioritizeMessage($data),
            'browse' => $this->formatBrowseMessage($data),
            'daily_schedule' => $this->formatDailyScheduleMessage($data),
            default => $this->formatDefaultMessage($data),
        };

        return trim($body);
    }

    /**
     * Turn internal filter_description strings into short, readable English.
     */
    public function humanizeFilterDescription(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (stripos($raw, 'no strong filters') !== false) {
            return 'A short list of your highest-ranked tasks (no extra filters right now).';
        }

        $parts = array_map(trim(...), explode(';', $raw));
        $out = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('/^time:\s*(.+)$/i', $part, $matches) === 1) {
                $token = trim(str_replace('_', ' ', $matches[1]));
                $out[] = $this->phraseTimeFilter($token);

                continue;
            }

            if (preg_match('/^priority:\s*(.+)$/i', $part, $matches) === 1) {
                $labels = array_map(trim(...), explode(',', $matches[1]));
                $out[] = 'Priority: '.implode(', ', $labels);

                continue;
            }

            if (preg_match('/^keywords\/tags\/title:\s*(.+)$/i', $part, $matches) === 1) {
                $out[] = 'Matching “'.trim($matches[1]).'” in titles or tags';

                continue;
            }

            if (preg_match('/^recurring tasks only$/i', $part) === 1) {
                $out[] = 'Recurring tasks only';

                continue;
            }

            $out[] = str_replace('_', ' ', $part);
        }

        return implode(' ', $out);
    }

    /**
     * @param  list<string>  $assumptions
     */
    public function formatAssumptionsPlain(array $assumptions): ?string
    {
        $clean = array_values(array_filter(
            array_map(static fn (mixed $line): string => trim((string) $line), $assumptions),
            static fn (string $line): bool => $line !== ''
        ));

        if ($clean === []) {
            return null;
        }

        if (count($clean) === 1) {
            return 'For context: '.$clean[0];
        }

        $bullets = array_map(static fn (string $s): string => '• '.$s, $clean);

        return "For context:\n".implode("\n", $bullets);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function formatPrioritizeMessage(array $data): string
    {
        $summary = trim((string) ($data['summary'] ?? ''));
        $reasoning = trim((string) ($data['reasoning'] ?? ''));
        $assistantNote = trim((string) ($data['assistant_note'] ?? ''));
        $strategyPoints = is_array($data['strategy_points'] ?? null) ? $data['strategy_points'] : [];
        $nextSteps = is_array($data['suggested_next_steps'] ?? null) ? $data['suggested_next_steps'] : [];
        $assumptions = is_array($data['assumptions'] ?? null) ? $data['assumptions'] : [];
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];

        $paragraphs = [];
        if ($summary !== '') {
            $paragraphs[] = $summary;
        }
        if ($reasoning !== '') {
            $paragraphs[] = 'Why these priorities: '.$reasoning;
        }

        $lines = $this->buildItemLines($items);
        if ($lines !== []) {
            $paragraphs[] = implode("\n", $lines);
        }

        $strategyPoints = $this->normalizeStringList($strategyPoints);
        if ($strategyPoints !== []) {
            $paragraphs[] = 'Focus strategy: '.$this->joinSentences($strategyPoints).'.';
        }

        $nextSteps = $this->normalizeStringList($nextSteps);
        if ($nextSteps !== []) {
            $paragraphs[] = 'Suggested next steps: '.$this->joinSentences($nextSteps).'.';
        }

        $assumptionsBlock = $this->formatAssumptionsPlain($assumptions);
        if ($assumptionsBlock !== null) {
            $paragraphs[] = $assumptionsBlock;
        }

        if ($assistantNote !== '') {
            $paragraphs[] = $assistantNote;
        }

        return trim(implode("\n\n", array_filter($paragraphs, static fn (string $p): bool => $p !== '')));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function formatBrowseMessage(array $data): string
    {
        $filterDescription = trim((string) ($data['filter_description'] ?? ''));
        $humanFilter = $this->humanizeFilterDescription($filterDescription);

        $summary = trim((string) ($data['summary'] ?? ''));
        $reasoning = trim((string) ($data['reasoning'] ?? ''));
        $assistantNote = trim((string) ($data['assistant_note'] ?? ''));
        $strategyPoints = is_array($data['strategy_points'] ?? null) ? $data['strategy_points'] : [];
        $nextSteps = is_array($data['suggested_next_steps'] ?? null) ? $data['suggested_next_steps'] : [];
        $assumptions = is_array($data['assumptions'] ?? null) ? $data['assumptions'] : [];
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];

        $paragraphs = [];

        if ($humanFilter !== '') {
            $paragraphs[] = 'Looking at: '.$humanFilter;
        }

        if ($summary !== '') {
            $paragraphs[] = $summary;
        }

        if ($reasoning !== '') {
            $paragraphs[] = 'Why this list: '.$reasoning;
        }

        $lines = $this->buildItemLines($items);
        if ($lines !== []) {
            $paragraphs[] = implode("\n", $lines);
        }

        $strategyPoints = $this->normalizeStringList($strategyPoints);
        if ($strategyPoints !== []) {
            $paragraphs[] = 'How to use this list: '.$this->joinSentences($strategyPoints).'.';
        }

        $nextSteps = $this->normalizeStringList($nextSteps);
        if ($nextSteps !== []) {
            $paragraphs[] = 'Suggested next steps: '.$this->joinSentences($nextSteps).'.';
        }

        $assumptionsBlock = $this->formatAssumptionsPlain($assumptions);
        if ($assumptionsBlock !== null) {
            $paragraphs[] = $assumptionsBlock;
        }

        if ($assistantNote !== '') {
            $paragraphs[] = $assistantNote;
        }

        return trim(implode("\n\n", array_filter($paragraphs, static fn (string $p): bool => $p !== '')));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function formatDailyScheduleMessage(array $data): string
    {
        $summary = trim((string) ($data['summary'] ?? ''));
        $reasoning = trim((string) ($data['reasoning'] ?? ''));
        $assistantNote = trim((string) ($data['assistant_note'] ?? ''));
        $blocks = $data['blocks'] ?? [];
        $strategyPoints = is_array($data['strategy_points'] ?? null) ? $data['strategy_points'] : [];
        $nextSteps = is_array($data['suggested_next_steps'] ?? null) ? $data['suggested_next_steps'] : [];
        $assumptions = is_array($data['assumptions'] ?? null) ? $data['assumptions'] : [];

        $paragraphs = [];
        if ($summary !== '') {
            $paragraphs[] = $summary;
        }
        if ($reasoning !== '') {
            $paragraphs[] = 'Why this schedule works: '.$reasoning;
        }

        if (is_array($blocks) && $blocks !== []) {
            $sentences = [];
            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    continue;
                }
                $start = (string) ($block['start_time'] ?? '');
                $end = (string) ($block['end_time'] ?? '');
                $label = (string) ($block['label'] ?? $block['title'] ?? 'Focus time');
                $reason = (string) ($block['reason'] ?? $block['note'] ?? '');
                $ref = $label;
                if ($block['task_id'] ?? null) {
                    $ref .= ' (task '.$block['task_id'].')';
                } elseif ($block['event_id'] ?? null) {
                    $ref .= ' (event '.$block['event_id'].')';
                }

                $time = trim($start.'–'.$end, '–');
                $sentence = ($time !== '' ? $time.': ' : '').$ref;
                if ($reason !== '') {
                    $sentence .= ' — '.$reason;
                }

                $sentences[] = $sentence;
            }

            if ($sentences !== []) {
                $paragraphs[] = 'Planned time blocks: '.$this->joinSentences($sentences);
            }
        }

        $strategyPoints = $this->normalizeStringList($strategyPoints);
        if ($strategyPoints !== []) {
            $paragraphs[] = 'Scheduling strategy: '.$this->joinSentences($strategyPoints).'.';
        }

        $nextSteps = $this->normalizeStringList($nextSteps);
        if ($nextSteps !== []) {
            $paragraphs[] = 'Suggested next steps: '.$this->joinSentences($nextSteps).'.';
        }

        $assumptionsBlock = $this->formatAssumptionsPlain($assumptions);
        if ($assumptionsBlock !== null) {
            $paragraphs[] = $assumptionsBlock;
        }

        $proposals = $data['proposals'] ?? [];
        if (is_array($proposals) && $proposals !== []) {
            $paragraphs[] = 'Use Accept/Decline on each proposed item to apply schedule updates.';
        }
        if ($assistantNote !== '') {
            $paragraphs[] = $assistantNote;
        }

        return implode("\n\n", $paragraphs);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function formatDefaultMessage(array $data): string
    {
        if (isset($data['message']) && is_string($data['message'])) {
            return $data['message'];
        }

        if (isset($data['summary']) && is_string($data['summary'])) {
            return $data['summary'];
        }

        return 'I\'ve processed your request. Is there anything specific you\'d like me to help you with next?';
    }

    private function phraseTimeFilter(string $token): string
    {
        $t = mb_strtolower($token);

        return match ($t) {
            'today' => 'tasks due today',
            'tomorrow' => 'tasks due tomorrow',
            'this week' => 'tasks due this week',
            'later afternoon' => 'tasks in the later afternoon window',
            'morning' => 'tasks in the morning window',
            'evening' => 'tasks in the evening window',
            default => 'tasks matching the “'.$token.'” time filter',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function buildItemLines(array $items): array
    {
        $lines = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $type = (string) ($item['entity_type'] ?? 'task');
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $reason = trim((string) ($item['reason'] ?? ''));
            $line = ($index + 1).". [{$type}] {$title}";
            if ($reason !== '') {
                $line .= ' - '.$reason;
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function normalizeStringList(array $values): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== ''
        ));
    }

    /**
     * @param  array<int, string>  $sentences
     */
    private function joinSentences(array $sentences): string
    {
        $sentences = array_values($sentences);
        $count = count($sentences);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $sentences[0];
        }
        if ($count === 2) {
            return $sentences[0].' and '.$sentences[1];
        }

        $last = array_pop($sentences);

        return implode(', ', $sentences).', and '.$last;
    }
}
