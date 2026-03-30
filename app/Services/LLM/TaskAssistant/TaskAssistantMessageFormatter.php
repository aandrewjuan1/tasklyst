<?php

namespace App\Services\LLM\TaskAssistant;

use App\Support\LLM\TaskAssistantPrioritizeOutputDefaults;

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
     * Prioritize body (student-visible order): optional acknowledgment; when Doing tasks exist,
     * doing_progress_coach then the in-progress title list, then framing (transition), then numbered ranked rows; otherwise framing
     * stays up front. Then filter_interpretation, reasoning (coach/why), then next_options last.
     * Same keys as prioritize validation in TaskAssistantResponseProcessor.
     *
     * @param  array{
     *   acknowledgment?: string,
     *   framing?: string,
     *   filter_interpretation?: string,
     *   doing_progress_coach?: string,
     *   doing_titles?: list<string>,
     *   reasoning?: string,
     *   next_options?: string,
     *   items?: list<array<string, mixed>>,
     *   suggested_guidance?: string,
     *   limit_used?: int
     * }  $data
     */
    private function formatPrioritizeListingMessage(array $data): string
    {
        $acknowledgment = trim((string) ($data['acknowledgment'] ?? ''));
        $framing = trim((string) ($data['framing'] ?? ''));
        if ($framing === '') {
            $framing = TaskAssistantPrioritizeOutputDefaults::reasoningWhenEmpty();
        }

        // Deduplicate when the narrative model/stress heuristics accidentally
        // produce overlapping acknowledgment + framing paragraphs.
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $singularCoerceCount = count($items);

        if ($acknowledgment !== '' && $framing !== '') {
            $ackNorm = $this->normalizeForDedupe($acknowledgment);
            $framingNorm = $this->normalizeForDedupe($framing);

            // Exact (after whitespace/case normalization) -> keep acknowledgment.
            if ($ackNorm !== '' && $ackNorm === $framingNorm) {
                $framing = '';
            } elseif (
                $ackNorm !== ''
                && $framingNorm !== ''
                && str_starts_with($framingNorm, $ackNorm)
                && mb_strlen($framingNorm) > (mb_strlen($ackNorm) + 8)
            ) {
                // Framing starts with acknowledgment -> keep framing.
                $acknowledgment = '';
            } elseif (
                $ackNorm !== ''
                && $framingNorm !== ''
                && str_starts_with($ackNorm, $framingNorm)
                && mb_strlen($ackNorm) > (mb_strlen($framingNorm) + 8)
            ) {
                // Acknowledgment starts with framing -> keep acknowledgment.
                $framing = '';
            } else {
                // Fallback: if they're extremely similar by token overlap,
                // avoid repeating both.
                $jaccard = $this->tokenJaccardSimilarity($ackNorm, $framingNorm);
                if ($jaccard >= 0.97) {
                    if (mb_strlen($framingNorm) >= mb_strlen($ackNorm)) {
                        $acknowledgment = '';
                    } else {
                        $framing = '';
                    }
                }
            }
        }

        if ($singularCoerceCount === 1) {
            $acknowledgment = TaskAssistantPrioritizeOutputDefaults::coerceSingularPrioritizeNarrative($acknowledgment, $singularCoerceCount, $items);
            $framing = TaskAssistantPrioritizeOutputDefaults::coerceSingularPrioritizeNarrative($framing, $singularCoerceCount, $items);
        }

        $reasoning = TaskAssistantPrioritizeOutputDefaults::stripRoboticPrioritizeReasoningTail(
            trim((string) ($data['reasoning'] ?? ''))
        );
        $nextOptions = trim((string) ($data['next_options'] ?? ''));

        $filterInterpretation = trim((string) ($data['filter_interpretation'] ?? ''));
        if ($filterInterpretation !== '' && $singularCoerceCount === 1) {
            $filterInterpretation = TaskAssistantPrioritizeOutputDefaults::coerceSingularPrioritizeNarrative(
                $filterInterpretation,
                $singularCoerceCount,
                $items
            );
        }

        $doingProgressCoach = trim((string) ($data['doing_progress_coach'] ?? ''));
        $doingTitles = is_array($data['doing_titles'] ?? null)
            ? array_values(array_filter(
                array_map(static fn (mixed $t): string => trim((string) $t), $data['doing_titles']),
                static fn (string $s): bool => $s !== ''
            ))
            : [];
        $doingTitleBlock = $this->formatDoingInProgressTitleLines($doingTitles);
        $lines = $this->formatPrioritizeItemLines($items);

        $paragraphs = [];

        if ($acknowledgment !== '') {
            $paragraphs[] = $acknowledgment;
        }

        $hasDoingSection = $doingProgressCoach !== '' || $doingTitleBlock !== '';
        if ($hasDoingSection) {
            if ($doingProgressCoach !== '') {
                $paragraphs[] = $doingProgressCoach;
            }
            if ($doingTitleBlock !== '') {
                $paragraphs[] = $doingTitleBlock;
            }
            if ($framing !== '') {
                $paragraphs[] = $framing;
            }
            if ($lines !== [] && ($doingProgressCoach !== '' || $doingTitleBlock !== '')) {
                $paragraphs[] = TaskAssistantPrioritizeOutputDefaults::prioritizeFormatterBridgeAfterDoingCoach($singularCoerceCount);
            }
        } else {
            $paragraphs[] = $framing;
        }

        if ($lines !== []) {
            $paragraphs[] = implode("\n", $lines);
        }

        if ($filterInterpretation !== '') {
            $paragraphs[] = $filterInterpretation;
        }

        if ($reasoning === '') {
            $reasoning = TaskAssistantPrioritizeOutputDefaults::reasoningWhenEmpty();
        }

        if ($singularCoerceCount === 1) {
            $reasoning = TaskAssistantPrioritizeOutputDefaults::coerceSingularPrioritizeNarrative($reasoning, $singularCoerceCount, $items);
        }

        $paragraphs[] = $reasoning;

        if ($nextOptions === '') {
            $nextOptions = $singularCoerceCount === 1
                ? __('If you want, I can schedule this for later.')
                : __('If you want, I can schedule these steps for later.');
        }

        if ($singularCoerceCount === 1) {
            $nextOptions = TaskAssistantPrioritizeOutputDefaults::coerceSingularPrioritizeNarrative($nextOptions, $singularCoerceCount, $items);
        }

        $paragraphs[] = $nextOptions;

        return trim(implode("\n\n", array_filter($paragraphs, static fn (string $p): bool => $p !== '')));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function formatGeneralGuidanceMessage(array $data): string
    {
        $acknowledgement = $this->normalizeGuidanceSentence((string) ($data['acknowledgement'] ?? ''));
        $message = $this->normalizeGuidanceSentence((string) ($data['message'] ?? ''));
        $suggestedNextActions = is_array($data['suggested_next_actions'] ?? null)
            ? $this->normalizeStringList($data['suggested_next_actions'])
            : [];

        $nextOptions = trim((string) ($data['next_options'] ?? ''));

        if ($acknowledgement === '' && $message === '' && $suggestedNextActions === [] && $nextOptions === '') {
            return 'I can help. What would you like to do next?';
        }

        $closingParagraph = '';
        if ($nextOptions !== '') {
            $closingParagraph = $this->normalizeGuidanceSentence($nextOptions);
        } elseif ($suggestedNextActions !== []) {
            $closingParagraph = $this->formatSuggestedNextActionsSentence($suggestedNextActions);
        }

        $segments = array_values(array_filter([
            $acknowledgement,
            $message,
            $closingParagraph,
        ], static fn (string $segment): bool => $segment !== ''));

        $unique = [];
        foreach ($segments as $segment) {
            $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $segment) ?? $segment));
            if ($normalized === '') {
                continue;
            }
            $isDuplicate = false;
            foreach ($unique as $existing) {
                if ($this->guidanceSimilarityScore($segment, $existing) >= 0.9) {
                    $isDuplicate = true;
                    break;
                }
            }
            if ($isDuplicate) {
                continue;
            }
            $unique[] = $segment;
        }

        return trim(implode("\n\n", $unique));
    }

    /**
     * @param  list<string>  $actions
     */
    private function formatSuggestedNextActionsSentence(array $actions): string
    {
        $actions = $this->normalizeStringList($actions);
        if ($actions === []) {
            return '';
        }

        $normalizedActions = array_map(
            static fn (string $action): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $action) ?? $action)),
            $actions
        );
        $hasPrioritize = false;
        $hasSchedule = false;
        foreach ($normalizedActions as $action) {
            if (str_contains($action, 'priorit')) {
                $hasPrioritize = true;
            }
            if (str_contains($action, 'schedule') || str_contains($action, 'time block')) {
                $hasSchedule = true;
            }
        }
        if ($hasPrioritize && $hasSchedule) {
            return 'Next, you can prioritize your tasks or schedule time blocks for your tasks.';
        }

        if (count($actions) === 1) {
            return 'Next, '.$actions[0];
        }

        if (count($actions) === 2) {
            return 'Next, '.$actions[0].' Or '.$actions[1];
        }

        return 'Next, '.$actions[0].' Or '.$actions[1].' Or '.$actions[2];
    }

    /**
     * @param  list<string>  $titles
     */
    private function formatDoingInProgressTitleLines(array $titles): string
    {
        if ($titles === []) {
            return '';
        }

        $lines = [];
        foreach ($titles as $index => $title) {
            $lines[] = ($index + 1).'. '.$title;
        }

        return __('In progress').":\n".implode("\n", $lines);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function formatPrioritizeItemLines(array $items): array
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

            $entityType = strtolower(trim((string) ($item['entity_type'] ?? 'task')));

            if ($entityType === 'event' || $entityType === 'project') {
                $kind = $entityType === 'event' ? __('Event') : __('Project');
                $lines[] = ($index + 1).'. '.$title.' — '.$kind;

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
                $complexity = TaskAssistantPrioritizeOutputDefaults::complexityNotSetLabel();
            }

            $detailParts = [];
            $detailParts[] = $priority.' priority';

            if ($dueOn !== '' && $dueOn !== '—') {
                $dueBucket = strtolower(trim((string) ($item['due_bucket'] ?? '')));
                if ($dueBucket === 'due_later' || mb_strtolower($duePhrase) === 'due later') {
                    $detailParts[] = 'Due '.$dueOn;
                } else {
                    $detailParts[] = $duePhrase !== '' ? $duePhrase.' ('.$dueOn.')' : $dueOn;
                }
            } else {
                $detailParts[] = $duePhrase !== ''
                    ? $duePhrase.' · '.TaskAssistantPrioritizeOutputDefaults::noDueDateLabel()
                    : TaskAssistantPrioritizeOutputDefaults::noDueDateLabel();
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
        $framing = trim((string) ($data['framing'] ?? ''));
        $reasoning = trim((string) ($data['reasoning'] ?? ''));
        $confirmation = trim((string) ($data['confirmation'] ?? ''));

        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $blocks = is_array($data['blocks'] ?? null) ? $data['blocks'] : [];

        $paragraphs = [];
        if ($framing !== '') {
            $paragraphs[] = $framing;
        }

        if ($items !== []) {
            $lines = [];
            foreach ($items as $idx => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $title = trim((string) ($item['title'] ?? ''));
                if ($title === '') {
                    $title = 'Focus time';
                }
                $duration = $item['duration_minutes'] ?? null;
                $durPart = is_numeric($duration) && (int) $duration > 0
                    ? ' (~'.(int) $duration.' min)'
                    : '';

                $block = is_array($blocks[$idx] ?? null) ? $blocks[$idx] : [];
                $start = (string) ($block['start_time'] ?? '');
                $end = (string) ($block['end_time'] ?? '');
                $timeStart = $this->formatHhmmLabel($start);
                $timeEnd = $this->formatHhmmLabel($end);
                $time = ($timeStart !== '' && $timeEnd !== '') ? $timeStart.'–'.$timeEnd : '';

                $startDatetime = (string) ($item['start_datetime'] ?? '');
                $endDatetime = (string) ($item['end_datetime'] ?? '');
                $dateLabel = $this->formatDateLabel($startDatetime);
                if ($dateLabel === '' && $endDatetime !== '') {
                    $dateLabel = $this->formatDateLabel($endDatetime);
                }

                if ($dateLabel !== '' && $time !== '') {
                    $line = '• '.$title.' — '.$dateLabel.' · '.$time.$durPart;
                } elseif ($time !== '') {
                    $line = '• '.$title.' — '.$time.$durPart;
                } elseif ($dateLabel !== '') {
                    $line = '• '.$title.' — '.$dateLabel.$durPart;
                } else {
                    $line = '• '.$title.$durPart;
                }
                $lines[] = $line;
            }
            if ($lines !== []) {
                $paragraphs[] = implode("\n", $lines);
            }
        }

        $digestNote = $this->formatSchedulePlacementDigestNote($data);
        if ($digestNote !== '') {
            $paragraphs[] = $digestNote;
        }

        if ($reasoning !== '') {
            $paragraphs[] = $reasoning;
        }
        if ($confirmation !== '') {
            $paragraphs[] = $confirmation;
        }

        $proposals = $data['proposals'] ?? [];
        if (is_array($proposals) && $proposals !== []) {
            $paragraphs[] = 'If the times and lengths above look right, use Accept all below to save them to your tasks. If anything should move or run longer or shorter, say what you need in chat and we will adjust before you save.';
        }

        return implode("\n\n", $paragraphs);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function formatSchedulePlacementDigestNote(array $data): string
    {
        $digest = $data['placement_digest'] ?? null;
        if (! is_array($digest)) {
            return '';
        }

        $daysUsed = is_array($digest['days_used'] ?? null) ? $digest['days_used'] : [];
        $unplaced = is_array($digest['unplaced_units'] ?? null) ? $digest['unplaced_units'] : [];
        $skipped = is_array($digest['skipped_targets'] ?? null) ? $digest['skipped_targets'] : [];

        $parts = [];

        if (count($daysUsed) > 1) {
            $parts[] = 'Some work was spread across '.count($daysUsed).' days to fit your time window.';
        }

        if ($unplaced !== []) {
            $reasons = [];
            foreach ($unplaced as $u) {
                if (! is_array($u)) {
                    continue;
                }
                $r = (string) ($u['reason'] ?? '');
                if ($r !== '') {
                    $reasons[] = $r;
                }
            }
            $reasons = array_values(array_unique($reasons));

            $hasCountLimit = in_array('count_limit', $reasons, true);
            $hasHorizonExhausted = in_array('horizon_exhausted', $reasons, true);

            if ($hasCountLimit && ! $hasHorizonExhausted) {
                $parts[] = 'I scheduled only up to the maximum number of items for this step; ask me to schedule the remaining ones too.';
            } else {
                if ($hasCountLimit) {
                    $parts[] = 'I scheduled only up to the maximum number of items for this step.';
                }
                $parts[] = 'One or more segments did not fit before the planning horizon; you can ask for a wider window or fewer items.';
            }
        }

        if ($skipped !== []) {
            $parts[] = 'Some targeted tasks could not be scheduled (missing or already completed).';
        }

        if ($parts === []) {
            return '';
        }

        return implode(' ', $parts);
    }

    private function formatDateLabel(string $datetime): string
    {
        $value = trim($datetime);
        if ($value === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($value))->format('M j, Y');
        } catch (\Throwable) {
            return '';
        }
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

    private function normalizeForDedupe(string $text): string
    {
        $t = trim($text);
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        return mb_strtolower($t);
    }

    private function normalizeGuidanceSentence(string $text): string
    {
        $value = trim($text);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\bthe your\b/iu', 'your', $value) ?? $value;
        $value = preg_replace('/\bconcrete your next step\b/iu', 'concrete next step', $value) ?? $value;
        $value = trim($value);

        return $value;
    }

    private function guidanceSimilarityScore(string $left, string $right): float
    {
        $a = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $left) ?? $left));
        $b = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $right) ?? $right));
        if ($a === '' || $b === '') {
            return 0.0;
        }

        $aTokens = preg_split('/[^\pL\pN]+/u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $bTokens = preg_split('/[^\pL\pN]+/u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($aTokens === [] || $bTokens === []) {
            return 0.0;
        }

        $aSet = array_values(array_unique($aTokens));
        $bSet = array_values(array_unique($bTokens));
        $intersection = count(array_intersect($aSet, $bSet));
        $union = count(array_unique(array_merge($aSet, $bSet)));
        if ($union === 0) {
            return 0.0;
        }

        return $intersection / $union;
    }

    private function tokenJaccardSimilarity(string $aNorm, string $bNorm): float
    {
        if ($aNorm === '' || $bNorm === '') {
            return 0.0;
        }

        $aTokens = preg_split('/[^\pL\pN]+/u', $aNorm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $bTokens = preg_split('/[^\pL\pN]+/u', $bNorm, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($aTokens === [] || $bTokens === []) {
            return 0.0;
        }

        $aSet = array_values(array_unique($aTokens));
        $bSet = array_values(array_unique($bTokens));

        $intersection = count(array_intersect($aSet, $bSet));
        $union = count(array_unique(array_merge($aSet, $bSet)));

        if ($union === 0) {
            return 0.0;
        }

        return $intersection / $union;
    }
}
