<?php

namespace App\Services\LLM\TaskAssistant;

use App\Support\LLM\TaskAssistantListingDefaults;

/**
 * Single place to turn validated structured assistant payloads into the user-visible message body.
 * Used for prioritize and daily_schedule flows.
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
            'prioritize' => $this->formatPrioritizeListingMessage($data),
            'general_guidance' => $this->formatGeneralGuidanceMessage($data),
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

            if (preg_match('/^domain:\s*school\b/i', $part) === 1) {
                $out[] = 'School coursework (subjects, teachers, or academic tags; generic errands excluded).';

                continue;
            }

            if (preg_match('/^domain:\s*chores/i', $part) === 1) {
                $out[] = 'Chores and household work (recurring when available).';

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
    public function formatAssumptionsPlain(array $assumptions, string $headingPrefix = 'For context'): ?string
    {
        $clean = array_values(array_filter(
            array_map(static fn (mixed $line): string => trim((string) $line), $assumptions),
            static fn (string $line): bool => $line !== ''
        ));

        if ($clean === []) {
            return null;
        }

        if (count($clean) === 1) {
            return $headingPrefix.': '.$clean[0];
        }

        $bullets = array_map(static fn (string $s): string => '• '.$s, $clean);

        return $headingPrefix.":\n".implode("\n", $bullets);
    }

    /**
     * Prioritize body: reasoning (short intro), then numbered items, then suggested_guidance paragraph.
     * Same keys as prioritize validation in TaskAssistantResponseProcessor.
     *
     * @param  array{
     *   reasoning?: string,
     *   items?: list<array<string, mixed>>,
     *   suggested_guidance?: string,
     *   limit_used?: int
     * }  $data
     */
    private function formatPrioritizeListingMessage(array $data): string
    {
        $reasoning = trim((string) ($data['reasoning'] ?? ''));
        if ($reasoning === '') {
            $reasoning = TaskAssistantListingDefaults::reasoningWhenEmpty();
        }
        $guidance = trim((string) ($data['suggested_guidance'] ?? ''));
        if ($guidance === '') {
            $guidance = TaskAssistantListingDefaults::defaultSuggestedGuidance();
        }
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];

        $paragraphs = [];

        $paragraphs[] = $reasoning;

        $lines = $this->formatBrowseItemLines($items);
        if ($lines !== []) {
            $paragraphs[] = implode("\n", $lines);
        }

        $paragraphs[] = $guidance;

        return trim(implode("\n\n", array_filter($paragraphs, static fn (string $p): bool => $p !== '')));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function formatGeneralGuidanceMessage(array $data): string
    {
        $response = trim((string) ($data['response'] ?? ''));
        $nextStepGuidance = trim((string) ($data['next_step_guidance'] ?? ''));
        $question = trim((string) ($data['clarifying_question'] ?? ''));

        if ($response !== '' && $question !== '' && str_contains($response, $question)) {
            $response = trim(str_replace($question, '', $response));
        }

        if ($response !== '' && $question !== '' && mb_stripos($response, 'would you like') !== false && str_contains($response, '?')) {
            // If the model leaked a redirect question into `message`, strip the
            // tail starting at the last question mark.
            $pos = mb_strrpos($response, '?');
            if ($pos !== false) {
                $response = trim(mb_substr($response, 0, $pos));
            }
        }

        if ($response === '' && $nextStepGuidance === '' && $question === '') {
            return 'I can help. What would you like to do next?';
        }

        // Keep the suggested flow as the final section for all guidance modes.
        $segments = array_values(array_filter([
            $response,
            $question,
            $nextStepGuidance,
        ], static fn (string $segment): bool => $segment !== ''));

        $unique = [];
        $seen = [];
        foreach ($segments as $segment) {
            $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $segment) ?? $segment));
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $unique[] = $segment;
        }

        return trim(implode("\n\n", $unique));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function formatBrowseItemLines(array $items): array
    {
        $lines = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $priority = ucfirst(trim((string) ($item['priority'] ?? 'medium')));
            if ($priority === '') {
                $priority = 'Medium';
            }
            $duePhrase = trim((string) ($item['due_phrase'] ?? ''));
            $dueOn = trim((string) ($item['due_on'] ?? ''));
            $complexity = trim((string) ($item['complexity_label'] ?? ''));
            if ($complexity === '') {
                $complexity = TaskAssistantListingDefaults::complexityNotSetLabel();
            }

            $detailParts = [];
            $detailParts[] = $priority.' priority';

            if ($dueOn !== '' && $dueOn !== '—') {
                $detailParts[] = $duePhrase !== '' ? $duePhrase.' ('.$dueOn.')' : $dueOn;
            } else {
                $detailParts[] = $duePhrase !== ''
                    ? $duePhrase.' · '.TaskAssistantListingDefaults::noDueDateLabel()
                    : TaskAssistantListingDefaults::noDueDateLabel();
            }

            $detailParts[] = 'Complexity: '.$complexity;

            $detail = implode(' · ', array_filter($detailParts, static fn (string $p): bool => $p !== ''));
            $lines[] = ($index + 1).'. '.$title.' — '.$detail;
        }

        return $lines;
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
            $paragraphs[] = $reasoning;
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
                $reason = trim((string) ($block['reason'] ?? $block['note'] ?? ''));

                // The blocks are deterministic; internal scheduler notes can look
                // noisy to end users, so we suppress them here.
                if (stripos($reason, 'planned by strict scheduler') !== false) {
                    $reason = '';
                }

                $timeStart = $this->formatHhmmLabel($start);
                $timeEnd = $this->formatHhmmLabel($end);
                $time = ($timeStart !== '' && $timeEnd !== '') ? $timeStart.'–'.$timeEnd : '';

                $sentence = $time !== ''
                    ? 'From '.$time.' you\'ll work on '.$label
                    : 'Work on '.$label;

                if ($reason !== '') {
                    $sentence .= ' — '.$reason;
                }

                $sentences[] = $sentence;
            }

            if ($sentences !== []) {
                $paragraphs[] = $this->joinSentences($sentences);
            }
        }

        $strategyPoints = $this->normalizeStringList($strategyPoints);
        if ($strategyPoints !== []) {
            $paragraphs[] = 'To make this schedule work, '.$this->joinSentences($strategyPoints).'.';
        }

        $nextSteps = $this->normalizeStringList($nextSteps);
        if ($nextSteps !== []) {
            $paragraphs[] = 'Next, '.$this->joinSentences($nextSteps).'.';
        }

        if ($assumptions !== []) {
            $cleanAssumptions = $this->normalizeStringList($assumptions);
            if ($cleanAssumptions !== []) {
                $paragraphs[] = 'I assumed that '.$this->joinSentences($cleanAssumptions).'.';
            }
        }

        $proposals = $data['proposals'] ?? [];
        if (is_array($proposals) && $proposals !== []) {
            $paragraphs[] = 'Accept or decline each proposed item to apply schedule updates.';
        }
        if ($assistantNote !== '') {
            $paragraphs[] = $assistantNote;
        }

        return implode("\n\n", $paragraphs);
    }

    private function formatHhmmLabel(string $hhmm): string
    {
        $hhmm = trim($hhmm);
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) {
            return '';
        }

        $hour24 = (int) ($m[1] ?? 0);
        $minute = (int) ($m[2] ?? 0);
        if ($hour24 < 0 || $hour24 > 23 || $minute < 0 || $minute > 59) {
            return '';
        }

        $ampm = $hour24 >= 12 ? 'PM' : 'AM';
        $hour12 = $hour24 % 12;
        if ($hour12 === 0) {
            $hour12 = 12;
        }

        return $hour12.':'.str_pad((string) $minute, 2, '0', STR_PAD_LEFT).' '.$ampm;
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
