<?php

namespace App\Services\LLM\TaskAssistant;

use App\Support\LLM\TaskAssistantPrioritizeOutputDefaults;
use App\Support\LLM\TaskAssistantScheduleNarrativeSanitizer;

/**
 * Single place to turn validated structured assistant payloads into the user-visible message body.
 * Used for prioritize and daily_schedule flows.
 */
final class TaskAssistantMessageFormatter
{
    public function __construct(
        private readonly TaskAssistantPrioritizeTemplateService $prioritizeTemplates,
    ) {}

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
            'listing_followup' => $this->formatListingFollowupMessage($data),
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
            return 'A focused slice of your highest-ranked tasks (no extra filters right now).';
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
     *   reasoning?: string,
     *   next_options?: string,
     *   count_mismatch_explanation?: string|null,
     *   items?: list<array<string, mixed>>,
     *   suggested_guidance?: string,
     *   limit_used?: int
     * }  $data
     */
    private function formatPrioritizeListingMessage(array $data): string
    {
        $acknowledgment = trim((string) ($data['acknowledgment'] ?? ''));
        $framing = trim((string) ($data['framing'] ?? ''));

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
        $countMismatchExplanation = is_string($data['count_mismatch_explanation'] ?? null)
            ? trim((string) $data['count_mismatch_explanation'])
            : '';
        $rankingMethodSummary = trim((string) ($data['ranking_method_summary'] ?? ''));
        $orderingRationale = is_array($data['ordering_rationale'] ?? null)
            ? array_values(array_filter(array_map(
                static fn (mixed $line): string => trim((string) $line),
                $data['ordering_rationale']
            ), static fn (string $line): bool => $line !== ''))
            : [];
        $assumptions = is_array($data['assumptions'] ?? null)
            ? array_values(array_filter(array_map(
                static fn (mixed $line): string => trim((string) $line),
                $data['assumptions']
            ), static fn (string $line): bool => $line !== ''))
            : [];

        $filterInterpretation = trim((string) ($data['filter_interpretation'] ?? ''));
        if ($filterInterpretation !== '' && $singularCoerceCount === 1) {
            $filterInterpretation = TaskAssistantPrioritizeOutputDefaults::coerceSingularPrioritizeNarrative(
                $filterInterpretation,
                $singularCoerceCount,
                $items
            );
        }

        $doingProgressCoach = trim((string) ($data['doing_progress_coach'] ?? ''));
        $hasDoingSection = $doingProgressCoach !== '';
        $lines = $this->formatPrioritizeItemLines($items);
        $hasRankedItems = $lines !== [];

        if (! $hasDoingSection) {
            $framing = $this->normalizePrioritizeFramingForRankedItems($framing, $items);
        }
        $reasoning = $this->normalizePrioritizeEffortPhrases($reasoning);

        $paragraphs = [];

        if ($acknowledgment !== '') {
            $paragraphs[] = $acknowledgment;
        }

        $hasOrderingRationale = $orderingRationale !== [];

        if ($hasDoingSection) {
            $paragraphs[] = $doingProgressCoach;
            if ($lines !== [] && ! $hasOrderingRationale) {
                $paragraphs[] = TaskAssistantPrioritizeOutputDefaults::prioritizeFormatterBridgeAfterDoingCoach($singularCoerceCount);
            }
        } else {
            if ($framing === '') {
                $seed = $this->prioritizeTemplates->buildSeedContextFromPrioritizePayload(
                    $data,
                    app()->bound('task_assistant.thread_id') ? (int) app('task_assistant.thread_id') : null,
                    'formatter_framing_empty',
                );
                $framing = $this->prioritizeTemplates->buildFramingInvalidFallback($singularCoerceCount, false, $seed);
            }
            $paragraphs[] = $framing;
        }

        if ($hasRankedItems && $rankingMethodSummary !== '') {
            $paragraphs[] = $rankingMethodSummary;
        }

        if ($hasOrderingRationale) {
            $paragraphs[] = implode("\n", array_map(
                static fn (string $line): string => '• '.$line,
                $orderingRationale
            ));
        } elseif ($hasRankedItems) {
            $paragraphs[] = implode("\n", $lines);
        }

        if ($countMismatchExplanation !== '') {
            $paragraphs[] = $countMismatchExplanation;
        }

        if ($filterInterpretation !== '') {
            $paragraphs[] = $filterInterpretation;
        }
        $assumptionsBlock = $this->formatAssumptionsPlain($assumptions);
        if ($assumptionsBlock !== null) {
            $paragraphs[] = $assumptionsBlock;
        }

        if ($reasoning === '') {
            $seed = $this->prioritizeTemplates->buildSeedContextFromPrioritizePayload(
                $data,
                app()->bound('task_assistant.thread_id') ? (int) app('task_assistant.thread_id') : null,
                'formatter_reasoning_empty',
            );
            $reasoning = $this->prioritizeTemplates->buildReasoningInvalidFallback($items, $hasDoingSection, $seed);
        }

        if ($orderingRationale !== []) {
            $orderingBlob = implode(' ', $orderingRationale);
            if ($this->tokenJaccardSimilarity($this->normalizeForDedupe($orderingBlob), $this->normalizeForDedupe($reasoning)) >= 0.62) {
                $orderingRationale = array_slice($orderingRationale, 0, 2);
            }
        }

        if ($orderingRationale !== []) {
            $firstOrdering = trim((string) ($orderingRationale[0] ?? ''));
            if (
                $firstOrdering !== ''
                && $this->tokenJaccardSimilarity(
                    $this->normalizeForDedupe($firstOrdering),
                    $this->normalizeForDedupe($reasoning)
                ) >= 0.72
            ) {
                $seed = $this->prioritizeTemplates->buildSeedContextFromPrioritizePayload(
                    $data,
                    app()->bound('task_assistant.thread_id') ? (int) app('task_assistant.thread_id') : null,
                    'formatter_ordering_similarity',
                );
                $reasoning = $this->prioritizeTemplates->buildReasoning($items, $hasDoingSection, $seed);
            }
        }

        if ($singularCoerceCount === 1) {
            $reasoning = TaskAssistantPrioritizeOutputDefaults::coerceSingularPrioritizeNarrative($reasoning, $singularCoerceCount, $items);
        }

        $paragraphs[] = $reasoning;

        if ($nextOptions === '') {
            $seed = $this->prioritizeTemplates->buildSeedContextFromPrioritizePayload(
                $data,
                app()->bound('task_assistant.thread_id') ? (int) app('task_assistant.thread_id') : null,
                'formatter_next_empty',
            );
            $next = $this->prioritizeTemplates->buildNextOptionsInvalidFallback($singularCoerceCount, $seed);
            $nextOptions = $next['next_options'];
        }

        if ($singularCoerceCount === 1) {
            $nextOptions = TaskAssistantPrioritizeOutputDefaults::coerceSingularPrioritizeNarrative($nextOptions, $singularCoerceCount, $items);
        }

        $paragraphs[] = $nextOptions;

        return trim(implode("\n\n", $this->dedupeParagraphs(
            array_values(array_filter($paragraphs, static fn (string $p): bool => $p !== ''))
        )));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function normalizePrioritizeFramingForRankedItems(string $framing, array $items): string
    {
        $framing = trim($framing);
        if ($framing === '' || $items === []) {
            return $framing;
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $framing, -1, PREG_SPLIT_NO_EMPTY) ?: [$framing];
        $firstSentence = trim((string) ($sentences[0] ?? $framing));
        if ($firstSentence === '') {
            $firstSentence = $framing;
        }

        $firstSentence = preg_replace('/\bhere (?:are|is)\s+\d+\s+(?:tasks?|items?|priorities)\b/iu', 'Here is your focused next-step slice', $firstSentence) ?? $firstSentence;
        $firstSentence = preg_replace('/\b(?:ordered by|ranked by)\b[^.?!]*/iu', '', $firstSentence) ?? $firstSentence;
        $firstSentence = preg_replace('/,\s*(?=[.?!]|$)/u', '', $firstSentence) ?? $firstSentence;
        $firstSentence = preg_replace('/\b(it’s|it\'s|it is)\s*(?:[.?!])?\s*$/iu', '', trim($firstSentence)) ?? $firstSentence;
        $firstSentence = preg_replace('/[—–-]\s*$/u', '', trim($firstSentence)) ?? $firstSentence;
        $firstSentence = preg_replace('/\s+([.?!])$/u', '$1', $firstSentence) ?? $firstSentence;
        $firstSentence = preg_replace('/\s{2,}/u', ' ', $firstSentence) ?? $firstSentence;

        return trim($firstSentence);
    }

    private function normalizePrioritizeEffortPhrases(string $text): string
    {
        $out = trim($text);
        if ($out === '') {
            return $out;
        }

        $replacements = [
            '/\bcomplex\s+complexity\b/iu' => 'higher effort',
            '/\bmoderate\s+complexity\b/iu' => 'manageable effort',
            '/\bsimple\s+complexity\b/iu' => 'quick effort',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $out = preg_replace($pattern, $replacement, $out) ?? $out;
        }

        return trim($out);
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
     * @param  array<string, mixed>  $data
     */
    private function formatListingFollowupMessage(array $data): string
    {
        $verdict = (string) ($data['verdict'] ?? 'partial');
        $verdictLine = match ($verdict) {
            'yes' => '**Short answer:** For the snapshot we just used, those items line up well with the top urgency band.',
            'no' => '**Short answer:** There are other items in your workspace that look more urgent than everything in that set.',
            default => '**Short answer:** It is partly aligned—some of it matches, but it is not a clean match end-to-end.',
        };

        $framing = trim((string) ($data['framing'] ?? ''));
        $rationale = trim((string) ($data['rationale'] ?? ''));
        $caveats = trim((string) ($data['caveats'] ?? ''));
        $next = trim((string) ($data['next_options'] ?? ''));

        $compared = is_array($data['compared_items'] ?? null) ? $data['compared_items'] : [];
        $comparedLines = [];
        foreach ($compared as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $title = $this->normalizeDisplayTitleSpacing($title);
            $comparedLines[] = '• '.$title;
        }

        $alts = is_array($data['more_urgent_alternatives'] ?? null) ? $data['more_urgent_alternatives'] : [];
        $altLines = [];
        foreach ($alts as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $title = $this->normalizeDisplayTitleSpacing($title);
            $altLines[] = '• '.$title;
        }

        $segments = array_values(array_filter([
            $verdictLine,
            $framing,
            $comparedLines !== [] ? "Items you asked about:\n".implode("\n", $comparedLines) : '',
            $altLines !== [] ? "Examples ranked ahead right now:\n".implode("\n", $altLines) : '',
            $rationale,
            $caveats !== '' ? $caveats : '',
            $next,
        ], static fn (string $s): bool => $s !== ''));

        return trim(implode("\n\n", $segments));
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
        // Deprecated: kept for backwards compatibility if other flows still call it.
        // Prefer formatDoingInProgressSummaryParagraph() for prioritize UX.
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
     * @param  list<string>  $titles
     */
    private function formatDoingInProgressSummaryParagraph(array $titles): string
    {
        $titles = array_values(array_filter(
            array_map(static fn (string $t): string => trim($t), $titles),
            static fn (string $t): bool => $t !== ''
        ));

        if ($titles === []) {
            return '';
        }

        if (count($titles) === 1) {
            return __('I see you already have something in progress: :title. If you can, finishing what you’re already doing first helps you stay steady and avoid feeling overwhelmed.', [
                'title' => $titles[0],
            ]);
        }

        $firstTwo = array_slice($titles, 0, 2);
        $more = count($titles) - count($firstTwo);
        $list = $this->joinSentences($firstTwo);

        if ($more > 0) {
            return __('I see you already have a few tasks in progress: :list, and :more more. Finishing what you’ve got underway first helps you move forward without juggling too much.', [
                'list' => $list,
                'more' => (string) $more,
            ]);
        }

        return __('I see you already have tasks in progress: :list. Finishing what you’ve got underway first helps you stay focused.', [
            'list' => $list,
        ]);
    }

    private function stripDoingTopPriorityLanguageFromFraming(string $framing): string
    {
        $framing = trim($framing);
        if ($framing === '') {
            return '';
        }

        // Remove “top priority first / top priorities first” and “ranked the next steps below”
        // phrasing when Doing exists. The ranked list will appear right after anyway.
        $patterns = [
            "/(?:Let's|Let’s)\\s+tackle\\s+your\\s+top\\s+priorit(?:y|ies)\\s+first[^.?!]*[.?!]?/iu",
            "/(?:I['’]ve|I have)\\s+ranked\\s+the\\s+next\\s+steps\\s+below[^.?!]*[.?!]?/iu",
            '/ranked\\s+the\\s+next\\s+steps\\s+below[^.?!]*[.?!]?/iu',
        ];

        foreach ($patterns as $pattern) {
            $framing = (string) preg_replace($pattern, '', $framing);
        }

        $framing = trim(preg_replace('/\\s+/u', ' ', $framing) ?? $framing);

        return $framing;
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
            $title = $this->normalizeDisplayTitleSpacing($title);

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
        $confirmationRequired = (bool) ($data['confirmation_required'] ?? false);
        if ($confirmationRequired) {
            return $this->formatScheduleFallbackConfirmationMessage($data);
        }

        $proposals = is_array($data['proposals'] ?? null) ? $data['proposals'] : [];
        $isPendingProposalNarrative = $this->shouldUsePendingProposalNarrative($data, $proposals);
        $hasSuccessfulProposals = count($proposals) > 0;
        $scheduleSource = trim((string) ($data['schedule_source'] ?? 'schedule'));
        $framing = TaskAssistantScheduleNarrativeSanitizer::sanitizeStudentFacingCopy(trim((string) ($data['framing'] ?? '')));
        $reasoning = TaskAssistantScheduleNarrativeSanitizer::sanitizeStudentFacingCopy(trim((string) ($data['reasoning'] ?? '')));
        $focusHistoryWindowExplanation = TaskAssistantScheduleNarrativeSanitizer::sanitizeStudentFacingCopy(trim((string) ($data['focus_history_window_explanation'] ?? '')));
        $confirmation = TaskAssistantScheduleNarrativeSanitizer::sanitizeStudentFacingCopy(trim((string) ($data['confirmation'] ?? '')));
        $framing = $this->normalizeCommitmentTone($framing, $isPendingProposalNarrative);
        $reasoning = $this->normalizeCommitmentTone($reasoning, $isPendingProposalNarrative);
        $confirmation = $this->normalizeCommitmentTone($confirmation, $isPendingProposalNarrative);
        $framing = $this->polishScheduleSentence($framing);
        $reasoning = $this->polishScheduleSentence($reasoning);
        $confirmation = $this->polishScheduleSentence($confirmation);

        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $blocks = is_array($data['blocks'] ?? null) ? $data['blocks'] : [];
        ['items' => $items, 'blocks' => $blocks] = $this->sortScheduleRowsForDisplay($items, $blocks);
        $framing = $this->harmonizeScheduleNarrativeDateMentions($items, $framing);
        $reasoning = $this->harmonizeScheduleNarrativeDateMentions($items, $reasoning);
        $confirmation = $this->harmonizeScheduleNarrativeDateMentions($items, $confirmation);
        $narrativeFacts = is_array($data['narrative_facts'] ?? null) ? $data['narrative_facts'] : [];
        $normalizedNarrative = $this->normalizeDailyScheduleNarrativeFields($items, $blocks, $framing, $reasoning, $confirmation, $narrativeFacts);
        $framing = $normalizedNarrative['framing'];
        $reasoning = $normalizedNarrative['reasoning'];
        $confirmation = $normalizedNarrative['confirmation'];
        $framing = $this->normalizeScheduleNarrativeForCardinality($framing, $items, $scheduleSource, 'framing');
        $reasoning = $this->normalizeScheduleNarrativeForCardinality($reasoning, $items, $scheduleSource, 'reasoning');
        $confirmation = $this->normalizeScheduleNarrativeForCardinality($confirmation, $items, $scheduleSource, 'confirmation');

        $paragraphs = [];
        if ($framing !== '') {
            $paragraphs[] = $framing;
        }

        $prioritizeSelectionExplanation = is_array($data['prioritize_selection_explanation'] ?? null)
            ? $data['prioritize_selection_explanation']
            : [];
        $hasImplicitPrioritizeSelectionExplanation = (bool) ($prioritizeSelectionExplanation['enabled'] ?? false)
            && trim((string) ($prioritizeSelectionExplanation['target_mode'] ?? '')) === 'implicit_ranked';
        $prioritizeSelectionParagraph = $this->formatPrioritizeSelectionExplanation($prioritizeSelectionExplanation);
        if ($prioritizeSelectionParagraph !== '') {
            $paragraphs[] = $prioritizeSelectionParagraph;
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
                $title = $this->normalizeDisplayTitleSpacing($title);
                $duration = $item['duration_minutes'] ?? null;
                $durPart = is_numeric($duration) && (int) $duration > 0
                    ? ' ('.$this->formatScheduleDurationLabel((int) $duration).')'
                    : '';

                $block = is_array($blocks[$idx] ?? null) ? $blocks[$idx] : [];
                $startDatetime = (string) ($item['start_datetime'] ?? '');
                $endDatetime = (string) ($item['end_datetime'] ?? '');
                $time = $this->resolveScheduleTimeLabelForRow($block, $startDatetime, $endDatetime);
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
                if ($hasSuccessfulProposals && $scheduleSource === 'prioritize_schedule' && ! $hasImplicitPrioritizeSelectionExplanation) {
                    $paragraphs[] = 'Here are your prioritized items, placed into schedule blocks:';
                }
                $paragraphs[] = implode("\n", $lines);
            }
        }

        $digestNote = $this->formatSchedulePlacementDigestNote($data);
        if ($digestNote !== '') {
            $paragraphs[] = $digestNote;
        }
        $windowSelectionExplanation = '';
        $windowSelectionStruct = is_array($data['window_selection_struct'] ?? null) ? $data['window_selection_struct'] : [];
        $suppressWindowSelectionParagraph = true;
        $orderingRationale = is_array($data['ordering_rationale'] ?? null) ? $data['ordering_rationale'] : [];
        $orderingRationaleStruct = is_array($data['ordering_rationale_struct'] ?? null) ? $data['ordering_rationale_struct'] : [];
        $blockingReasons = is_array($data['blocking_reasons'] ?? null) ? $data['blocking_reasons'] : [];
        $blockingReasonsStruct = is_array($data['blocking_reasons_struct'] ?? null) ? $data['blocking_reasons_struct'] : [];
        $fallbackChoiceExplanation = trim((string) ($data['fallback_choice_explanation'] ?? ''));
        $summary = trim((string) ($data['summary'] ?? ''));
        $assistantNote = trim((string) ($data['assistant_note'] ?? ''));
        $strategyPoints = is_array($data['strategy_points'] ?? null) ? $data['strategy_points'] : [];
        $suggestedNextSteps = is_array($data['suggested_next_steps'] ?? null) ? $data['suggested_next_steps'] : [];
        $assumptions = is_array($data['assumptions'] ?? null) ? $data['assumptions'] : [];
        $whyPlanLines = [];
        // Window-selection boilerplate lines are intentionally suppressed in student-facing output.
        if (! $hasSuccessfulProposals && $orderingRationaleStruct !== [] && $orderingRationale === []) {
            foreach ($orderingRationaleStruct as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $rank = (int) ($row['rank'] ?? 0);
                $title = trim((string) ($row['title'] ?? ''));
                $fitReasonCode = trim((string) ($row['fit_reason_code'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $prefix = $rank > 0 ? "#{$rank} {$title}" : $title;
                $whyPlanLines[] = $prefix.': '.$this->scheduleFitReasonFromCode($fitReasonCode);
            }
        }
        if (! $hasSuccessfulProposals) {
            foreach ($orderingRationale as $line) {
                $text = trim((string) $line);
                if ($text === '') {
                    continue;
                }
                $whyPlanLines[] = $text;
            }
        }
        if ($fallbackChoiceExplanation !== '') {
            $whyPlanLines[] = $fallbackChoiceExplanation;
        }
        if ($whyPlanLines !== []) {
            $whyPlanParagraph = $this->renderScheduleExplainabilityAsSentenceChain($whyPlanLines);
            if (! $this->scheduleParagraphsAreTooSimilar($whyPlanParagraph, $reasoning)) {
                $paragraphs[] = $whyPlanParagraph;
            }
        }
        if (! $hasSuccessfulProposals) {
            $blockingSectionTitle = $this->resolveBlockingSectionTitle($data);
            if ($blockingReasons !== []) {
                $blockerLines = $this->formatBlockingItemOnlyLines($blockingReasons);
                if ($blockerLines !== []) {
                    $paragraphs[] = $blockingSectionTitle."\n".implode("\n", $blockerLines);
                }
            } elseif ($blockingReasonsStruct !== []) {
                $blockerLines = $this->formatBlockingItemOnlyLines($blockingReasonsStruct);
                if ($blockerLines !== []) {
                    $paragraphs[] = $blockingSectionTitle."\n".implode("\n", $blockerLines);
                }
            }
        }
        $strategyLines = array_values(array_filter(array_map(
            static fn (mixed $line): string => trim((string) $line),
            $strategyPoints
        ), static fn (string $line): bool => $line !== ''));
        if ($strategyLines !== []) {
            $paragraphs[] = "Strategy highlights:\n".implode("\n", array_map(
                static fn (string $line): string => '• '.$line,
                $strategyLines
            ));
        }
        $nextStepLines = array_values(array_filter(array_map(
            static fn (mixed $line): string => trim((string) $line),
            $suggestedNextSteps
        ), static fn (string $line): bool => $line !== ''));
        if ($nextStepLines !== []) {
            $paragraphs[] = "Suggested next steps:\n".implode("\n", array_map(
                static fn (string $line): string => '• '.$line,
                $nextStepLines
            ));
        }
        $assumptionsBlock = $this->formatAssumptionsPlain($assumptions, 'Planning assumptions');
        if ($assumptionsBlock !== null) {
            $paragraphs[] = $assumptionsBlock;
        }
        if ($assistantNote !== '') {
            $paragraphs[] = $assistantNote;
        }
        if ($summary !== '') {
            $paragraphs[] = $summary;
        }

        if ($reasoning !== '') {
            $paragraphs[] = $this->humanizeIsoDateRanges($reasoning);
        }
        if ($focusHistoryWindowExplanation !== '') {
            $paragraphs[] = $this->humanizeIsoDateRanges($focusHistoryWindowExplanation);
        }
        if ($confirmation !== '') {
            $paragraphs[] = $this->humanizeIsoDateRanges($confirmation);
        }

        return implode("\n\n", $this->dedupeParagraphs(
            array_values(array_filter($paragraphs, static fn (string $p): bool => trim($p) !== ''))
        ));
    }

    /**
     * @param  array<string, mixed>  $explanation
     */
    private function formatPrioritizeSelectionExplanation(array $explanation): string
    {
        if (! (bool) ($explanation['enabled'] ?? false)) {
            return '';
        }

        $summary = trim((string) ($explanation['summary'] ?? ''));
        $selectionBasis = trim((string) ($explanation['selection_basis'] ?? ''));
        $selectedCount = max(1, (int) ($explanation['selected_count'] ?? 1));
        $paragraph = trim(implode(' ', array_values(array_filter([
            $summary,
            $selectionBasis,
        ], static fn (string $value): bool => $value !== ''))));

        return $this->enforceThreeSentencePrioritizeSelectionParagraph($paragraph, $selectedCount);
    }

    private function enforceThreeSentencePrioritizeSelectionParagraph(string $paragraph, int $selectedCount): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $paragraph) ?? $paragraph);
        if ($value === '') {
            return '';
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $normalized = [];
        foreach ($sentences as $sentence) {
            $line = trim((string) $sentence);
            if ($line === '') {
                continue;
            }
            $normalized[] = $line;
            if (count($normalized) === 3) {
                break;
            }
        }

        $fallbacks = $selectedCount <= 1
            ? [
                'I selected this task first because it stood out as the clearest next step before scheduling.',
                'I weigh urgency first, then explicit priority and due timing.',
                'When signals are close, I favor a shorter focused block so this stays doable.',
            ]
            : [
                'I selected these tasks first because they stood out as the clearest priorities before scheduling.',
                'I weigh urgency first, then explicit priority and due timing.',
                'When signals are close, I favor shorter focused blocks so the plan stays doable.',
            ];

        $index = 0;
        while (count($normalized) < 3 && isset($fallbacks[$index])) {
            $candidate = $fallbacks[$index];
            $candidateNorm = mb_strtolower(trim($candidate));
            $exists = false;
            foreach ($normalized as $existing) {
                if (mb_strtolower(trim($existing)) === $candidateNorm) {
                    $exists = true;
                    break;
                }
            }
            if (! $exists) {
                $normalized[] = $candidate;
            }
            $index++;
        }

        return trim(implode(' ', array_slice($normalized, 0, 3)));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function normalizeScheduleNarrativeForCardinality(
        string $text,
        array $items,
        string $scheduleSource,
        string $field
    ): string {
        $value = trim($text);
        $itemCount = count($items);
        if ($value === '' || $itemCount <= 1) {
            return $value;
        }

        $replacements = [
            '/\bI proposed this at\b/iu' => 'I proposed this plan starting at',
            '/\bI suggested moving this to the next conflict-free slot\b/iu' => 'I suggested the next conflict-free slots that fit this plan',
            '/\bI proposed moving this to the next conflict-free slot\b/iu' => 'I proposed the next conflict-free slots that fit this plan',
            '/\bI moved this to the next conflict-free slot\b/iu' => 'I moved these tasks into the next conflict-free slots',
            '/\bI proposed this in the closest feasible window\b/iu' => 'I proposed these tasks in the closest feasible windows',
            '/\bI placed this in the closest feasible window\b/iu' => 'I placed these tasks in the closest feasible windows',
            '/\bI placed this\b/iu' => 'I placed these tasks',
            '/\bI proposed this\b(?!\s+plan)/iu' => 'I proposed this plan',
            '/\bthis task\b/iu' => 'these tasks',
            '/\bthis slot\b/iu' => 'this plan',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? $value;
        }

        if ($field === 'confirmation') {
            $value = preg_replace('/\bshift earlier\/later\b/iu', 'shift some of these blocks earlier or later', $value) ?? $value;
        }

        return trim($value);
    }

    private function scheduleParagraphsAreTooSimilar(string $left, string $right): bool
    {
        $leftNorm = $this->normalizeForDedupe($left);
        $rightNorm = $this->normalizeForDedupe($right);
        if ($leftNorm === '' || $rightNorm === '') {
            return false;
        }

        return $this->tokenJaccardSimilarity($leftNorm, $rightNorm) >= 0.62;
    }

    private function scheduleFitReasonFromCode(string $fitReasonCode): string
    {
        return match ($fitReasonCode) {
            'strongest_fit_window' => 'placed in the strongest fit window for momentum and feasibility.',
            'next_fit_window' => 'placed in the next feasible window that avoids conflicts.',
            default => 'placed in a feasible window based on your constraints.',
        };
    }

    private function scheduleBlockReasonFromCode(string $reasonCode): string
    {
        return match ($reasonCode) {
            'count_limit_reached' => 'not scheduled yet because this pass reached the current item limit.',
            'window_conflict' => 'overlaps your requested window constraints.',
            default => 'no free slot was available inside your requested window.',
        };
    }

    /**
     * @param  list<string>  $lines
     */
    private function renderScheduleExplainabilityAsSentenceChain(array $lines): string
    {
        $clean = array_values(array_filter(array_map(
            static function (mixed $line): string {
                $value = trim((string) $line);
                if ($value === '') {
                    return '';
                }

                // Strip legacy bullet prefixes if any older payload still includes them.
                $value = ltrim($value, "• \t");

                return trim($value);
            },
            $lines
        ), static fn (string $line): bool => $line !== ''));

        if ($clean === []) {
            return '';
        }
        if (count($clean) === 1) {
            return $clean[0];
        }

        $chain = [];
        foreach ($clean as $index => $line) {
            if ($index === 0) {
                $chain[] = $line;

                continue;
            }

            $connector = match ($index) {
                1 => 'Also, ',
                2 => 'And ',
                default => 'Additionally, ',
            };
            $lineNormalized = preg_replace('/^[A-Z][a-z]+,\s+/u', '', $line) ?? $line;
            $lineNormalized = trim($lineNormalized);
            if ($lineNormalized === '') {
                continue;
            }
            $chain[] = $connector.$lineNormalized;
        }

        return implode(' ', $chain);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $blocks
     * @return array{
     *   framing: string,
     *   reasoning: string,
     *   confirmation: string,
     *   corrections: array<string, array{from: string, to: string}>
     * }
     */
    public function normalizeDailyScheduleNarrativeFields(
        array $items,
        array $blocks,
        string $framing,
        string $reasoning,
        string $confirmation,
        array $narrativeFacts = [],
    ): array {
        $facts = $this->buildScheduleNarrativeFacts($items, $blocks, $narrativeFacts);
        $corrections = [];
        $normalized = [
            'framing' => $this->applyScheduleNarrativeCorrections($framing, $facts, 'framing', $corrections),
            'reasoning' => $this->applyScheduleNarrativeCorrections($reasoning, $facts, 'reasoning', $corrections),
            'confirmation' => $this->applyScheduleNarrativeCorrections($confirmation, $facts, 'confirmation', $corrections),
        ];
        foreach (['framing', 'reasoning', 'confirmation'] as $field) {
            $value = trim((string) ($normalized[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            if ($this->containsHardBlockedSchedulePhrases($value)) {
                $normalized[$field] = $this->rewriteScheduleNarrativeForMentorTone($field, $items, $value);
                $corrections[$field.'_hard_block_rewrite'] = [
                    'from' => $value,
                    'to' => (string) $normalized[$field],
                ];
            }
        }

        return [
            ...$normalized,
            'corrections' => $corrections,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function formatScheduleFallbackConfirmationMessage(array $data): string
    {
        $ctx = is_array($data['confirmation_context'] ?? null) ? $data['confirmation_context'] : [];
        $reasonCode = trim((string) ($ctx['reason_code'] ?? ''));
        $reason = TaskAssistantScheduleNarrativeSanitizer::sanitizeStudentFacingCopy(trim((string) ($ctx['reason_message'] ?? '')));
        $prompt = TaskAssistantScheduleNarrativeSanitizer::sanitizeStudentFacingCopy(trim((string) ($ctx['prompt'] ?? '')));
        $reason = $this->normalizeCommitmentTone($reason, true);
        $prompt = $this->normalizeCommitmentTone($prompt, true);
        $reason = $this->polishScheduleSentence($reason);
        $prompt = $this->polishScheduleSentence($prompt);
        $preview = is_array($data['fallback_preview'] ?? null) ? $data['fallback_preview'] : [];

        $paragraphs = [];
        $framing = TaskAssistantScheduleNarrativeSanitizer::sanitizeStudentFacingCopy(trim((string) ($data['framing'] ?? '')));
        if ($framing !== '') {
            $framing = $this->normalizeCommitmentTone($framing, true);
            $framing = $this->polishScheduleSentence($framing);
            $paragraphs[] = $framing;
        }
        if ($reason !== '') {
            $paragraphs[] = $reason;
        }

        $proposalsCount = (int) ($preview['proposals_count'] ?? 0);
        if ($proposalsCount > 0) {
            // Intentionally avoid showing technical summary lines for students.
        }

        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $blocks = is_array($data['blocks'] ?? null) ? $data['blocks'] : [];
        ['items' => $items, 'blocks' => $blocks] = $this->sortScheduleRowsForDisplay($items, $blocks);
        if ($items !== []) {
            $lines = [];
            foreach ($items as $idx => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $title = trim((string) ($item['title'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $title = $this->normalizeDisplayTitleSpacing($title);
                $block = is_array($blocks[$idx] ?? null) ? $blocks[$idx] : [];
                $dateLabel = $this->formatDateLabel((string) ($item['start_datetime'] ?? ''));
                $timeLabel = $this->resolveScheduleTimeLabelForRow(
                    $block,
                    (string) ($item['start_datetime'] ?? ''),
                    (string) ($item['end_datetime'] ?? '')
                );
                $line = '• '.$title;
                if ($dateLabel !== '') {
                    $line .= ' — '.$dateLabel;
                }
                if ($timeLabel !== '') {
                    $line .= ' · '.$timeLabel;
                }
                $lines[] = $line;
            }
            if ($lines !== []) {
                $paragraphs[] = $this->buildFallbackDraftHeading($ctx);
                $paragraphs[] = implode("\n", $lines);
            }
        }

        $reasonDetails = is_array($ctx['reason_details'] ?? null) ? $ctx['reason_details'] : [];
        $reasonDetailsBlock = $this->formatFallbackReasonDetails($reasonDetails);
        if ($reasonDetailsBlock !== '') {
            $paragraphs[] = $reasonDetailsBlock;
        }
        $blockingReasons = is_array($data['blocking_reasons'] ?? null) ? $data['blocking_reasons'] : [];
        if ($blockingReasons !== []) {
            $blockingReasons = array_values(array_filter($blockingReasons, static function (mixed $row): bool {
                if (! is_array($row)) {
                    return false;
                }

                $reason = mb_strtolower(trim((string) ($row['reason'] ?? '')));

                return str_contains($reason, 'overlap');
            }));
            $blockerLines = $this->formatBlockingItemOnlyLines($blockingReasons);
            if ($blockerLines !== []) {
                $paragraphs[] = $this->resolveBlockingSectionTitle($data)."\n".implode("\n", $blockerLines);
            }
        }

        if ($prompt !== '') {
            $paragraphs[] = $prompt;
        }

        return trim(implode("\n\n", $paragraphs));
    }

    /**
     * @param  array<string, mixed>  $confirmationContext
     */
    private function buildFallbackDraftHeading(array $confirmationContext): string
    {
        $requested = (int) ($confirmationContext['requested_count'] ?? 0);
        $placed = (int) ($confirmationContext['placed_count'] ?? 0);
        if ($requested > 0 && $placed > 0 && $placed <= $requested) {
            return "Here is what I can schedule now ({$placed} of {$requested}):";
        }

        return 'Here is what I can schedule now:';
    }

    /**
     * @param  array<int, mixed>  $reasonDetails
     */
    private function formatFallbackReasonDetails(array $reasonDetails): string
    {
        $lines = [];
        foreach ($reasonDetails as $detail) {
            $text = TaskAssistantScheduleNarrativeSanitizer::sanitizeStudentFacingCopy(trim((string) $detail));
            if ($text === '') {
                continue;
            }
            $lines[] = '• '.$text;
        }

        if ($lines === []) {
            return '';
        }

        return "What got in the way:\n".implode("\n", array_slice($lines, 0, 3));
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<string>
     */
    private function formatBlockingItemOnlyLines(array $rows): array
    {
        $lines = [];
        $seen = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? 'Busy item'));
            $title = preg_replace('/^pending_schedule:\s*/iu', '', $title) ?? $title;
            $window = trim((string) ($row['blocked_window'] ?? ''));
            if ($title === '') {
                continue;
            }
            $line = $window !== '' ? "• {$title} ({$window})" : "• {$title}";
            $dedupeKey = mb_strtolower($line);
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveBlockingSectionTitle(array $data): string
    {
        $explicit = trim((string) ($data['blocking_section_title'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $windowLabel = trim((string) ($data['requested_window_display_label'] ?? ''));
        $horizonLabel = trim((string) ($data['requested_horizon_label'] ?? ''));
        $target = $horizonLabel !== '' ? $horizonLabel : $windowLabel;
        if ($target === '') {
            $target = 'your requested window';
        }

        return match ($target) {
            'today' => 'These items are already scheduled for today:',
            'tomorrow' => 'These items are already scheduled for tomorrow:',
            'this week' => "These items are already scheduled in this week's window:",
            'next week' => "These items are already scheduled in next week's window:",
            default => "These items are already scheduled in {$target}:",
        };
    }

    /**
     * @param  array<int, mixed>  $options
     */
    private function formatScheduleConfirmationOptions(array $options): string
    {
        $lines = [];
        foreach ($options as $option) {
            $text = TaskAssistantScheduleNarrativeSanitizer::sanitizeStudentFacingCopy(trim((string) $option));
            if ($text !== '') {
                $lines[] = $text;
            }
        }

        if ($lines === []) {
            return '';
        }

        $numbered = [];
        foreach ($lines as $index => $line) {
            $numbered[] = ($index + 1).') '.$line;
        }

        return "Options:\n".implode("\n", $numbered);
    }

    private function formatScheduleDurationLabel(int $minutes): string
    {
        if ($minutes <= 0) {
            return '';
        }

        // Keep short blocks compact in minutes; switch to hour-based wording for longer blocks.
        if ($minutes <= 60) {
            return '~'.$minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;
        $hourLabel = $hours === 1 ? 'hr' : 'hrs';

        if ($remainingMinutes === 0) {
            return '~'.$hours.' '.$hourLabel;
        }

        return '~'.$hours.' '.$hourLabel.' '.$remainingMinutes.' min';
    }

    private function polishScheduleSentence(string $text): string
    {
        $value = trim($text);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\binto in\b/iu', 'into', $value) ?? $value;
        $value = preg_replace('/\bbefore you save\b/iu', 'before we lock it in', $value) ?? $value;
        $value = preg_replace('/\b2-block run\b/iu', 'two focused blocks', $value) ?? $value;
        $value = preg_replace('/\bearliest realistic windows\b/iu', 'time that fits your availability', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @param  list<array<string, mixed>>  $proposals
     */
    private function shouldUsePendingProposalNarrative(array $data, array $proposals): bool
    {
        if ((bool) ($data['confirmation_required'] ?? false)) {
            return true;
        }

        $mode = trim((string) data_get($data, 'explanation_meta.narrative_mode', ''));
        if ($mode !== '') {
            return $mode === 'pending_proposal';
        }

        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            if (mb_strtolower(trim((string) ($proposal['status'] ?? ''))) === 'pending') {
                return true;
            }
        }

        return false;
    }

    private function normalizeCommitmentTone(string $text, bool $pending): string
    {
        $value = trim($text);
        if ($value === '' || ! $pending) {
            return $value;
        }

        $value = preg_replace('/\bI scheduled\b/iu', 'I proposed', $value) ?? $value;
        $value = preg_replace('/\bI placed\b/iu', 'I proposed', $value) ?? $value;
        $value = preg_replace('/\bI kept\b/iu', 'I proposed keeping', $value) ?? $value;
        $value = preg_replace('/\bbefore you finalize\b/iu', 'before you confirm', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $blocks
     * @return array{items: list<array<string, mixed>>, blocks: list<array<string, mixed>>}
     */
    private function sortScheduleRowsForDisplay(array $items, array $blocks): array
    {
        if (count($items) <= 1 || count($items) !== count($blocks)) {
            return ['items' => $items, 'blocks' => $blocks];
        }

        $rows = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $start = trim((string) ($item['start_datetime'] ?? ''));
            $timestamp = $start !== '' ? strtotime($start) : false;
            $rows[] = [
                'index' => $index,
                'timestamp' => $timestamp === false ? PHP_INT_MAX : (int) $timestamp,
            ];
        }

        if (count($rows) !== count($items)) {
            return ['items' => $items, 'blocks' => $blocks];
        }

        usort($rows, static function (array $left, array $right): int {
            if ($left['timestamp'] === $right['timestamp']) {
                return $left['index'] <=> $right['index'];
            }

            return $left['timestamp'] <=> $right['timestamp'];
        });

        $sortedItems = [];
        $sortedBlocks = [];
        foreach ($rows as $row) {
            $index = (int) ($row['index'] ?? 0);
            $sortedItems[] = $items[$index];
            $sortedBlocks[] = $blocks[$index];
        }

        return ['items' => $sortedItems, 'blocks' => $sortedBlocks];
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
        $partial = is_array($digest['partial_units'] ?? null) ? $digest['partial_units'] : [];
        $suppressBulkUnplaced = (bool) ($digest['suppress_bulk_unplaced_narrative'] ?? false);
        $requestedCountSource = (string) ($digest['requested_count_source'] ?? 'system_default');

        $parts = [];

        if (count($daysUsed) > 1) {
            $parts[] = 'Some work was spread across '.count($daysUsed).' days to fit your time window.';
        }

        if ($unplaced !== [] && ! $suppressBulkUnplaced) {
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

            if ($requestedCountSource !== 'explicit_user' && $hasHorizonExhausted) {
                $parts[] = 'I scheduled what fit in your requested window. If you want, I can find extra slots tomorrow or later this week.';
            } elseif ($hasCountLimit && ! $hasHorizonExhausted) {
                $parts[] = 'I scheduled only up to the maximum number of items for this step; ask me to schedule the remaining ones too.';
            } else {
                if ($hasCountLimit) {
                    $parts[] = 'I scheduled only up to the maximum number of items for this step.';
                }
                $parts[] = 'One or more segments did not fit in the selected schedule window; you can ask for a wider window or fewer items.';
            }
        }

        if ($partial !== []) {
            if (count($partial) === 1) {
                $single = is_array($partial[0] ?? null) ? $partial[0] : [];
                $title = trim((string) ($single['title'] ?? 'this task'));
                $requested = (int) ($single['requested_minutes'] ?? 0);
                $placed = (int) ($single['placed_minutes'] ?? 0);

                $detail = '';
                if ($requested > 0 && $placed > 0 && $placed < $requested) {
                    $detail = " ({$this->formatScheduleDurationLabel($placed)} scheduled of {$this->formatScheduleDurationLabel($requested)} requested)";
                }

                $parts[] = "I scheduled {$title}{$detail} within your available time window. If you want, I can adjust the time window or schedule another block.";
            } else {
                $parts[] = 'Some tasks did not fully fit in the available time window, so I scheduled what could fit first. If you want, I can widen the window or add follow-up blocks.';
            }
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

    private function humanizeIsoDateRanges(string $text): string
    {
        $value = trim($text);
        if ($value === '') {
            return '';
        }

        $value = (string) preg_replace_callback(
            '/\b(\d{4}-\d{2}-\d{2})\s+(to|-)\s+(\d{4}-\d{2}-\d{2})\b/u',
            function (array $matches): string {
                $start = $this->formatIsoDateForNarrative((string) ($matches[1] ?? ''));
                $end = $this->formatIsoDateForNarrative((string) ($matches[3] ?? ''));
                if ($start === '' || $end === '') {
                    return (string) ($matches[0] ?? '');
                }

                return $start.' to '.$end;
            },
            $value
        );

        $value = (string) preg_replace_callback(
            '/\b\d{4}-\d{2}-\d{2}\b/u',
            fn (array $matches): string => $this->formatIsoDateForNarrative((string) ($matches[0] ?? '')) ?: (string) ($matches[0] ?? ''),
            $value
        );

        return $value;
    }

    private function formatIsoDateForNarrative(string $isoDate): string
    {
        try {
            return (new \DateTimeImmutable($isoDate))->format('M j');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Render row time from block HH:MM first, then safely fall back to item datetimes.
     *
     * @param  array<string, mixed>  $block
     */
    private function resolveScheduleTimeLabelForRow(array $block, string $startDatetime, string $endDatetime): string
    {
        $timeStart = $this->formatHhmmLabel((string) ($block['start_time'] ?? ''));
        $timeEnd = $this->formatHhmmLabel((string) ($block['end_time'] ?? ''));
        if ($timeStart !== '' && $timeEnd !== '') {
            return $timeStart.'–'.$timeEnd;
        }

        $startFromItem = $this->formatTimeLabelFromIsoDatetime($startDatetime);
        $endFromItem = $this->formatTimeLabelFromIsoDatetime($endDatetime);

        if ($startFromItem !== '' && $endFromItem !== '') {
            return $startFromItem.'–'.$endFromItem;
        }

        return '';
    }

    private function formatTimeLabelFromIsoDatetime(string $datetime): string
    {
        $value = trim($datetime);
        if ($value === '') {
            return '';
        }

        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return '';
        }

        return $this->formatHhmmLabel($date->format('H:i'));
    }

    private function normalizeDisplayTitleSpacing(string $title): string
    {
        $normalized = trim($title);
        if ($normalized === '') {
            return '';
        }

        // Display-only cleanup: keep source title semantics, only collapse odd spacing.
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function harmonizeScheduleNarrativeDateMentions(array $items, string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $dateMap = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $start = trim((string) ($item['start_datetime'] ?? ''));
            if ($start === '') {
                continue;
            }
            try {
                $d = (new \DateTimeImmutable($start))->format('Y-m-d');
            } catch (\Throwable) {
                continue;
            }
            $dateMap[$d] = true;
        }

        if (count($dateMap) !== 1) {
            return $text;
        }

        $targetDate = (string) array_key_first($dateMap);
        try {
            $target = new \DateTimeImmutable($targetDate);
        } catch (\Throwable) {
            return $text;
        }

        $targetShort = $target->format('M j, Y');
        $targetIso = $target->format('Y-m-d');
        $targetSlash = $target->format('m/d/Y');
        $targetPlain = $target->format('F j');

        $hasRelativeDateLanguage = preg_match('/\b(today|tomorrow|tonight|this week|next week|weekend)\b/iu', $text) === 1;
        if (! $hasRelativeDateLanguage) {
            return $text;
        }

        $normalized = preg_replace_callback(
            '/\b(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t|tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\s+\d{1,2}(?:st|nd|rd|th)?(?:,\s*\d{4})?\b/iu',
            static fn (): string => $targetShort,
            $text
        );
        $normalized = preg_replace('/\b\d{4}-\d{2}-\d{2}\b/u', $targetIso, (string) $normalized);
        $normalized = preg_replace('/\b(0?[1-9]|1[0-2])\/([0-2]?\d|3[01])(?:\/\d{2,4})?\b/u', $targetSlash, (string) $normalized);

        if (is_string($normalized) && stripos($normalized, $targetPlain) === false && stripos($normalized, $targetShort) === false) {
            return $text;
        }

        return is_string($normalized) ? $normalized : $text;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $blocks
     * @return array{
     *   is_multi_day: bool,
     *   span_label: string,
     *   dominant_daypart: string|null,
     *   first_slot_daypart: string|null
     * }
     */
    private function buildScheduleNarrativeFacts(array $items, array $blocks, array $narrativeFacts = []): array
    {
        $dates = [];
        $hours = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $start = trim((string) ($item['start_datetime'] ?? ''));
            if ($start !== '') {
                try {
                    $dt = new \DateTimeImmutable($start);
                    $dates[$dt->format('Y-m-d')] = true;
                    $hours[] = (int) $dt->format('H');
                } catch (\Throwable) {
                    // Ignore malformed datetime entries.
                }
            }

            $block = is_array($blocks[$index] ?? null) ? $blocks[$index] : [];
            $blockHour = $this->extractHourFromHhmm((string) ($block['start_time'] ?? ''));
            if ($blockHour !== null) {
                $hours[] = $blockHour;
            }
        }

        $sortedDates = array_keys($dates);
        sort($sortedDates);
        $isMultiDay = count($sortedDates) > 1;
        $spanLabel = '';
        if ($sortedDates !== []) {
            try {
                $first = (new \DateTimeImmutable($sortedDates[0]))->format('M j, Y');
                $last = (new \DateTimeImmutable($sortedDates[count($sortedDates) - 1]))->format('M j, Y');
                $spanLabel = $first === $last ? $first : $first.' to '.$last;
            } catch (\Throwable) {
                $spanLabel = '';
            }
        }

        $relativeDayLabel = null;
        $requestedLabel = mb_strtolower(trim((string) ($narrativeFacts['requested_horizon_label'] ?? '')));
        if ($requestedLabel === 'today' || $requestedLabel === 'tomorrow' || $requestedLabel === 'tonight') {
            $relativeDayLabel = $requestedLabel === 'tonight' ? 'today' : $requestedLabel;
        }

        return [
            'is_multi_day' => $isMultiDay,
            'span_label' => $spanLabel,
            'dominant_daypart' => $this->dominantDaypartFromHours($hours),
            'first_slot_daypart' => $this->firstSlotDaypartFromItems($items),
            'relative_day_label' => $relativeDayLabel,
        ];
    }

    /**
     * @param  array{
     *   is_multi_day: bool,
     *   span_label: string,
     *   dominant_daypart: string|null,
     *   first_slot_daypart: string|null,
     *   relative_day_label: string|null
     * }  $facts
     * @param  array<string, array{from: string, to: string}>  $corrections
     */
    private function applyScheduleNarrativeCorrections(string $text, array $facts, string $field, array &$corrections): string
    {
        $normalized = trim($text);
        if ($normalized === '') {
            return '';
        }

        $updated = $normalized;
        $from = $normalized;

        if ($facts['is_multi_day']) {
            $updated = (string) preg_replace('/\b(today|tonight)\b/iu', 'this schedule window', $updated);
            if ($field === 'framing' && ! preg_match('/\b(across|spans?)\b/iu', $updated)) {
                $span = trim((string) $facts['span_label']);
                if ($span !== '') {
                    $updated = "Here is your plan across {$span}. ".ltrim($updated);
                }
            }
        } else {
            $updated = (string) preg_replace('/\bI spread placements across\b[^.?!]*[.?!]?\s*/iu', '', $updated);
            $singleDayLabel = is_string($facts['relative_day_label'] ?? null) ? (string) $facts['relative_day_label'] : '';
            if ($singleDayLabel === 'tomorrow') {
                $updated = (string) preg_replace('/\b(today|tonight)\b/iu', 'tomorrow', $updated);
            } elseif ($singleDayLabel === 'today') {
                $updated = (string) preg_replace('/\btomorrow\b/iu', 'today', $updated);
            }
        }

        $firstSlotDaypart = is_string($facts['first_slot_daypart'] ?? null)
            ? (string) $facts['first_slot_daypart']
            : null;
        if ($firstSlotDaypart !== null) {
            $updated = $this->alignStartDaypartClaimToFirstSlot($updated, $firstSlotDaypart);
        }

        $dominantDaypart = $facts['dominant_daypart'];
        if (is_string($dominantDaypart) && $dominantDaypart !== '') {
            $updated = $this->alignGenericDaypartClaimsToDominantDaypart($updated, $dominantDaypart);
        }

        if ($updated !== $from) {
            $updated = $this->normalizeSentenceStartCasing($updated);
            $corrections[$field] = [
                'from' => $from,
                'to' => $updated,
            ];
        }

        return $updated;
    }

    private function normalizeSentenceStartCasing(string $text): string
    {
        $value = trim($text);
        if ($value === '') {
            return '';
        }

        $value = (string) preg_replace_callback(
            '/(^|[.!?]\s+)([a-z])/u',
            static fn (array $matches): string => (string) ($matches[1] ?? '').mb_strtoupper((string) ($matches[2] ?? '')),
            $value
        );

        return $value;
    }

    private function containsHardBlockedSchedulePhrases(string $value): bool
    {
        $normalized = mb_strtolower($value);
        foreach ([
            'earliest realistic windows',
            'biggest work starts first',
            'follow-up blocks stay lighter',
            'across your planned blocks between',
        ] as $blocked) {
            if (str_contains($normalized, $blocked)) {
                return true;
            }
        }

        return false;
    }

    private function alignGenericDaypartClaimsToDominantDaypart(string $text, string $dominantDaypart): string
    {
        $updated = $text;

        return match ($dominantDaypart) {
            'morning' => (string) preg_replace('/\b(evening|night|afternoon)\b(?!\s+start\b)/iu', 'morning', $updated),
            'afternoon' => (string) preg_replace('/\b(evening|night|morning)\b(?!\s+start\b)/iu', 'afternoon', $updated),
            'evening' => (string) preg_replace('/\b(morning|afternoon)\b(?!\s+start\b)/iu', 'evening', $updated),
            default => $updated,
        };
    }

    /**
     * Keep "start" narratives grounded on the actual first scheduled slot.
     * This avoids rewriting a concrete "10:20 AM start" into "afternoon start"
     * when later rows dominate the schedule.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function firstSlotDaypartFromItems(array $items): ?string
    {
        $firstStart = trim((string) data_get($items, '0.start_datetime', ''));
        if ($firstStart === '') {
            return null;
        }

        try {
            $hour = (int) (new \DateTimeImmutable($firstStart))->format('H');
        } catch (\Throwable) {
            return null;
        }

        return match (true) {
            $hour < 12 => 'morning',
            $hour < 18 => 'afternoon',
            default => 'evening',
        };
    }

    private function alignStartDaypartClaimToFirstSlot(string $text, string $firstSlotDaypart): string
    {
        $updated = $text;

        $patterns = [
            '/\b(a|an)\s+(morning|afternoon|evening)\s+start\b/iu',
            '/\b(morning|afternoon|evening)\s+start\b/iu',
        ];

        foreach ($patterns as $pattern) {
            $updated = (string) preg_replace_callback($pattern, function (array $matches) use ($firstSlotDaypart): string {
                $hasArticle = isset($matches[1]) && in_array(mb_strtolower((string) $matches[1]), ['a', 'an'], true);
                $article = in_array($firstSlotDaypart, ['afternoon', 'evening'], true) ? 'an' : 'a';
                $phrase = $hasArticle ? "{$article} {$firstSlotDaypart} start" : "{$firstSlotDaypart} start";

                return $phrase;
            }, $updated);
        }

        return $updated;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function rewriteScheduleNarrativeForMentorTone(string $field, array $items, string $fallback): string
    {
        if (count($items) <= 1) {
            $title = trim((string) data_get($items, '0.title', 'this task'));
            if ($title === '') {
                $title = 'this task';
            }
            $timeLabel = $this->formatTimeRangeLabel(
                (string) data_get($items, '0.start_datetime', ''),
                (string) data_get($items, '0.end_datetime', '')
            );

            return match ($field) {
                'framing' => $timeLabel !== ''
                    ? "I placed {$title} at {$timeLabel} so you have one clear block to focus on."
                    : "I placed {$title} in one clear block so you can get started quickly.",
                'reasoning' => 'This keeps the plan specific and realistic for the time you have today.',
                'confirmation' => 'If you want a different slot, tell me and I will shift it.',
                default => $fallback,
            };
        }

        return match ($field) {
            'framing' => 'I arranged these blocks in a practical order that fits your available windows.',
            'reasoning' => 'This sequence keeps each block focused and avoids overloading any single part of the day.',
            'confirmation' => 'If any block should move, tell me which one and I will adjust it.',
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

    private function extractHourFromHhmm(string $value): ?int
    {
        $hhmm = trim($value);
        if (preg_match('/^(\d{1,2}):\d{2}$/', $hhmm, $matches) !== 1) {
            return null;
        }

        $hour = (int) ($matches[1] ?? -1);

        return $hour >= 0 && $hour <= 23 ? $hour : null;
    }

    /**
     * @param  list<int>  $hours
     */
    private function dominantDaypartFromHours(array $hours): ?string
    {
        if ($hours === []) {
            return null;
        }

        $counts = [
            'morning' => 0,
            'afternoon' => 0,
            'evening' => 0,
        ];

        foreach ($hours as $hour) {
            if ($hour < 12) {
                $counts['morning']++;

                continue;
            }
            if ($hour < 18) {
                $counts['afternoon']++;

                continue;
            }
            $counts['evening']++;
        }

        arsort($counts);
        $winner = array_key_first($counts);

        return is_string($winner) ? $winner : null;
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

    /**
     * @param  list<string>  $paragraphs
     * @return list<string>
     */
    private function dedupeParagraphs(array $paragraphs): array
    {
        $unique = [];
        foreach ($paragraphs as $paragraph) {
            $candidate = trim($paragraph);
            if ($candidate === '') {
                continue;
            }
            $norm = $this->normalizeForDedupe($candidate);
            $isDuplicate = false;
            foreach ($unique as $existing) {
                $existingNorm = $this->normalizeForDedupe($existing);
                if ($norm === $existingNorm || $this->tokenJaccardSimilarity($norm, $existingNorm) >= 0.9) {
                    $isDuplicate = true;
                    break;
                }
            }
            if (! $isDuplicate) {
                $unique[] = $candidate;
            }
        }

        return $unique;
    }
}
