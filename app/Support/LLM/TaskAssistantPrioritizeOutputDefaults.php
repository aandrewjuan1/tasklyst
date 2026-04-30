<?php

namespace App\Support\LLM;

/**
 * User-visible clamps and defaults for the prioritize (rank) flow: narrative fields, doing coach, and formatter bridges.
 */
final class TaskAssistantPrioritizeOutputDefaults
{
    public static function maxFramingChars(): int
    {
        // Must match TaskAssistantResponseProcessor::validatePrioritizeListingData().
        $max = (int) config('task-assistant.listing.max_framing_chars', 900);

        return max(80, min($max, self::maxSuggestedGuidanceChars()));
    }

    /**
     * Max length for rank-variant deterministic doing-progress coach text.
     *
     * Must match TaskAssistantResponseProcessor::validatePrioritizeListingData().
     */
    public static function maxDoingProgressCoachChars(): int
    {
        $max = (int) config('task-assistant.listing.max_doing_progress_coach_chars', 600);

        return max(200, min($max, 1200));
    }

    public static function clampDoingProgressCoach(string $text): string
    {
        $max = self::maxDoingProgressCoachChars();
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1).'…';
    }

    /**
     * @param  list<string>  $orderedTitles
     */
    public static function buildDoingProgressCoach(array $orderedTitles, int $totalCount): ?string
    {
        if ($totalCount <= 0) {
            return null;
        }

        $clean = [];
        foreach ($orderedTitles as $t) {
            $s = trim((string) $t);
            if ($s !== '') {
                $clean[] = $s;
            }
        }

        if ($clean === []) {
            return null;
        }

        // UX: mention only 1-2 Doing titles in prose; if there are more, include "N more".
        $maxSample = 2;
        $sample = array_slice($clean, 0, $maxSample);
        $more = max(0, $totalCount - count($sample));
        $list = implode(', ', $sample);

        if ($totalCount === 1 && count($sample) === 1) {
            $body = __('You already have one task in progress: :title. If you can, finishing it before you take on something new usually means less switching.', [
                'title' => $sample[0],
            ]);
        } elseif ($more > 0) {
            $body = __('You have :total tasks in progress: :list, and :more more. When you can, closing one before opening another often feels calmer than juggling several.', [
                'total' => $totalCount,
                'list' => $list,
                'more' => $more,
            ]);
        } else {
            $body = __('You have :total tasks in progress: :list. When you can, closing one before opening another often feels calmer than juggling several.', [
                'total' => $totalCount,
                'list' => $list,
            ]);
        }

        return self::clampDoingProgressCoach((string) $body);
    }

    /**
     * Title-free coach line when Doing titles are rendered separately (avoids duplicating names in prose).
     * Used as fallback when structured output omits doing_progress_coach but Doing tasks exist.
     */
    public static function buildDoingProgressCoachMotivationFallback(int $totalCount): ?string
    {
        if ($totalCount <= 0) {
            return null;
        }

        $body = $totalCount === 1
            ? (string) __('Finishing what you already started before adding something new usually means less mental switching—and a clearer head for what comes next.')
            : (string) __('You have several tasks underway; when you can, closing one before opening another often feels calmer than juggling many at once.');

        return self::clampDoingProgressCoach($body);
    }

    public static function framingWhenRankSliceHasNoTodoButDoing(): string
    {
        return (string) __('No other tasks surfaced in this slice yet—finishing what you started will unlock the next priorities.');
    }

    /**
     * True when $text contains a substring match for any ranked row title (ITEMS_JSON).
     *
     * @param  list<array<string, mixed>>  $items
     */
    public static function proseContainsAnyRankedItemTitle(string $text, array $items, int $minTitleChars = 4): bool
    {
        $c = trim($text);
        if ($c === '' || $items === []) {
            return false;
        }

        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if (mb_strlen($title) < max(1, $minTitleChars)) {
                continue;
            }
            if (mb_stripos($c, $title) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when doing_progress_coach names or quotes a title from the ranked slice (ITEMS_JSON).
     * Those titles must not appear in doing_progress_coach; Doing-only titles are not in $items.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public static function doingProgressCoachLeaksRankedSliceTitles(string $coach, array $items): bool
    {
        return self::proseContainsAnyRankedItemTitle($coach, $items);
    }

    /**
     * Remove Doing-coach sentences that smuggle in distinctive words or phrases from ranked rows
     * when those strings do not also appear in any Doing title (e.g. “notes”, “lecture”, “quiz review”).
     *
     * @param  list<array<string, mixed>>  $rankedItems
     * @param  list<string>  $doingTitles
     */
    public static function sanitizeDoingProgressCoachAgainstRankedContentBleed(
        string $coach,
        array $rankedItems,
        array $doingTitles,
    ): string {
        $coach = trim($coach);
        if ($coach === '' || $rankedItems === []) {
            return $coach;
        }

        $markers = self::rankedSliceMarkersAbsentFromDoingTitles($rankedItems, $doingTitles);
        if ($markers === []) {
            return $coach;
        }

        $sentences = self::splitNarrativeSentences($coach);
        if ($sentences === []) {
            return $coach;
        }

        $kept = [];
        foreach ($sentences as $sent) {
            $s = trim((string) $sent);
            if ($s === '') {
                continue;
            }
            if (self::narrativeSentenceMatchesAnyDoingCoachBleedMarker($s, $markers)) {
                continue;
            }
            $kept[] = $s;
        }

        return trim(implode(' ', $kept));
    }

    /**
     * Drop reasoning sentences that pull in distinctive tokens or phrases from ranked rows 2+ when the
     * student slice has multiple items—reasoning should justify row #1 without borrowing other rows’ subjects.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public static function stripReasoningSentencesBleedingSecondaryRankedRows(string $reasoning, array $items): string
    {
        $reasoning = trim($reasoning);
        if ($reasoning === '' || count($items) < 2) {
            return $reasoning;
        }

        $first = $items[0] ?? null;
        if (! is_array($first)) {
            return $reasoning;
        }

        $firstTitle = trim((string) ($first['title'] ?? ''));
        if ($firstTitle === '') {
            return $reasoning;
        }

        $firstLower = mb_strtolower($firstTitle);
        $markers = self::secondaryRankedRowMarkersVersusFirst($items, $firstLower);
        if ($markers === []) {
            return $reasoning;
        }

        $sentences = self::splitNarrativeSentences($reasoning);
        if ($sentences === []) {
            return $reasoning;
        }

        $kept = [];
        foreach ($sentences as $sent) {
            $s = trim((string) $sent);
            if ($s === '') {
                continue;
            }
            if (self::narrativeSentenceMatchesAnyDoingCoachBleedMarker($s, $markers)) {
                continue;
            }
            $kept[] = $s;
        }

        return trim(implode(' ', $kept));
    }

    /**
     * Remove reasoning sentences that invent worksheets / practice sets / similar artifacts not present in titles.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public static function stripReasoningSentencesWithInventedStudyArtifacts(string $reasoning, array $items): string
    {
        $reasoning = trim($reasoning);
        if ($reasoning === '') {
            return $reasoning;
        }

        $titlesBlob = self::prioritizeTitlesBlobLower($items);
        if ($titlesBlob === '') {
            return $reasoning;
        }

        $patterns = [
            '/\bpractice\s+problems?\b/iu',
            '/\bproblem\s+sets?\b/iu',
            '/\bpractice\s+sets?\b/iu',
            '/\bworksheets?\b/iu',
            '/\bmock\s+exams?\b/iu',
        ];
        $extra = config('task-assistant.listing.prioritize_reasoning_invented_artifact_patterns', []);
        if (is_array($extra)) {
            foreach ($extra as $pattern) {
                if (is_string($pattern) && $pattern !== '') {
                    $patterns[] = $pattern;
                }
            }
        }

        $sentences = self::splitNarrativeSentences($reasoning);
        if ($sentences === []) {
            return $reasoning;
        }

        $kept = [];
        foreach ($sentences as $sent) {
            $s = trim((string) $sent);
            if ($s === '') {
                continue;
            }
            $drop = false;
            foreach ($patterns as $pattern) {
                if (@preg_match($pattern, $s, $m) !== 1) {
                    continue;
                }
                $hit = mb_strtolower(trim((string) ($m[0] ?? '')));
                if ($hit !== '' && mb_strpos($titlesBlob, $hit) === false) {
                    $drop = true;

                    break;
                }
            }
            if (! $drop) {
                $kept[] = $s;
            }
        }

        return trim(implode(' ', $kept));
    }

    /**
     * When the slice is a single ranked row, drop reasoning sentences that pull in distinctive Doing-only titles
     * (course codes, lab lines, etc.) so the paragraph stays anchored to row #1 only.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $doingTitles
     */
    public static function stripReasoningSentencesEchoingDoingTitlesWhenSingleRankedRow(
        string $reasoning,
        array $items,
        array $doingTitles,
    ): string {
        $reasoning = trim($reasoning);
        if ($reasoning === '' || count($items) !== 1 || $doingTitles === []) {
            return $reasoning;
        }

        $first = $items[0] ?? null;
        if (! is_array($first)) {
            return $reasoning;
        }

        $firstTitle = trim((string) ($first['title'] ?? ''));
        if ($firstTitle === '') {
            return $reasoning;
        }

        $cleanDoing = array_values(array_filter(
            array_map(static fn (mixed $t): string => trim((string) $t), $doingTitles),
            static fn (string $s): bool => $s !== ''
        ));
        if ($cleanDoing === []) {
            return $reasoning;
        }

        $markers = self::doingTitleBleedMarkersVersusFirstRanked($cleanDoing, mb_strtolower($firstTitle));
        if ($markers === []) {
            return $reasoning;
        }

        usort($markers, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $sentences = self::splitNarrativeSentences($reasoning);
        if ($sentences === []) {
            return $reasoning;
        }

        $kept = [];
        foreach ($sentences as $sent) {
            $s = trim((string) $sent);
            if ($s === '') {
                continue;
            }
            if (self::narrativeSentenceMatchesAnyDoingCoachBleedMarker($s, $markers)) {
                continue;
            }
            $kept[] = $s;
        }

        $out = trim(implode(' ', $kept));

        if ($out === '') {
            return self::minimalCoachReasoningForFirstRankedRow($items);
        }

        return $out;
    }

    /**
     * Framing appears before the formatter prints the ranked list; strip vague "this/these" task references.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public static function refineFramingPrematureDeicticBeforeRankedList(
        string $framing,
        array $items,
        bool $doingCoachRequired,
        ?string $diversifier = null,
    ): string {
        if (! $doingCoachRequired || $items === []) {
            return $framing;
        }

        $patterns = config('task-assistant.listing.prioritize_framing_premature_deictic_sentence_patterns', []);
        if (! is_array($patterns) || $patterns === []) {
            return $framing;
        }

        $f = trim($framing);
        if ($f === '') {
            return self::clampFraming(self::buildPrioritizeFramingDoingFirstIntroFallback($diversifier));
        }

        $parts = self::splitNarrativeSentences($f);
        if ($parts === []) {
            $parts = [$f];
        }

        $kept = [];
        foreach ($parts as $sent) {
            $s = trim((string) $sent);
            if ($s === '') {
                continue;
            }
            $drop = false;
            foreach ($patterns as $pattern) {
                if (! is_string($pattern) || $pattern === '') {
                    continue;
                }
                if (@preg_match($pattern, $s) === 1) {
                    $drop = true;

                    break;
                }
            }
            if (! $drop) {
                $kept[] = $s;
            }
        }

        $out = trim(implode(' ', $kept));
        $minKept = (int) config('task-assistant.listing.prioritize_framing_when_doing_min_chars_after_strip', 40);
        $minKept = max(20, min(200, $minKept));

        if ($out === '' || mb_strlen($out) < $minKept) {
            return self::clampFraming(self::buildPrioritizeFramingDoingFirstIntroFallback($diversifier));
        }

        return self::clampFraming($out);
    }

    /**
     * Remove framing sentences that rehash acknowledgment themes (stress, prep, quiz) when token overlap is high.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public static function dedupePrioritizeFramingVersusAcknowledgment(
        ?string $acknowledgment,
        string $framing,
        array $items,
        bool $doingCoachRequired,
        ?string $diversifier = null,
    ): string {
        $ack = $acknowledgment !== null ? trim($acknowledgment) : '';
        if ($ack === '') {
            return $framing;
        }

        $f = trim($framing);
        if ($f === '') {
            return $framing;
        }

        $threshold = (float) config('task-assistant.listing.prioritize_framing_ack_dedupe_sentence_jaccard', 0.42);
        $threshold = max(0.22, min(0.88, $threshold));
        $themePattern = '/\b(quiz|quizzes|exam|exams|test|tests|prep|prepare|studying|study|overwhelm|overwhelmed|stressed|stress|catch up|catching up|deadline|priority|priorities|confidence|focus|focusing)\b/iu';

        $ackThemed = @preg_match($themePattern, $ack) === 1;

        $parts = self::splitNarrativeSentences($f);
        if ($parts === []) {
            $parts = [$f];
        }

        $kept = [];
        foreach ($parts as $sent) {
            $s = trim((string) $sent);
            if ($s === '') {
                continue;
            }

            $sim = self::narrativeTokenJaccardSimilarity($s, $ack);
            $sentThemed = @preg_match($themePattern, $s) === 1;

            if ($ackThemed && $sentThemed && $sim >= $threshold) {
                continue;
            }

            $kept[] = $s;
        }

        $out = trim(implode(' ', $kept));
        $minKept = (int) config('task-assistant.listing.prioritize_framing_when_doing_min_chars_after_strip', 40);
        $minKept = max(18, min(200, $minKept));

        if ($out === '' || mb_strlen($out) < $minKept) {
            if ($doingCoachRequired && $items !== []) {
                return self::clampFraming(self::buildPrioritizeFramingDoingFirstIntroFallback($diversifier));
            }

            return self::clampFraming(
                $out !== ''
                    ? $out
                    : (string) __('When you’re ready, the ranked next step is below—use it after you steady what’s already in motion.')
            );
        }

        return self::clampFraming($out);
    }

    /**
     * Short grounded reasoning when heavier sanitization removed overly broad sentences.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private static function minimalCoachReasoningForFirstRankedRow(array $items): string
    {
        $first = $items[0] ?? null;
        if (! is_array($first)) {
            return self::reasoningWhenEmpty();
        }

        $title = trim((string) ($first['title'] ?? ''));
        if ($title === '') {
            return self::reasoningWhenEmpty();
        }

        $due = mb_strtolower(trim((string) ($first['due_phrase'] ?? '')));
        $complexity = mb_strtolower(trim((string) ($first['complexity_label'] ?? '')));
        $priority = mb_strtolower(trim((string) ($first['priority'] ?? '')));

        $bits = [];
        if ($due !== '' && $due !== 'no due date') {
            $bits[] = $due;
        }
        if (in_array($priority, ['urgent', 'high'], true)) {
            $bits[] = $priority === 'urgent' ? (string) __('urgent priority') : (string) __('high priority');
        }
        if ($complexity !== '' && $complexity !== 'simple') {
            $bits[] = (string) __('complexity: :label', ['label' => $first['complexity_label'] ?? $complexity]);
        }

        $detail = $bits === [] ? '' : ' ('.implode(', ', $bits).')';
        $lead = (string) __('I’d put “:title” first in this slice', ['title' => $title]);
        $tail = (string) __('—it reflects the strongest time pressure right now.');

        return self::clampPrioritizeReasoning($lead.$detail.$tail);
    }

    /**
     * Distinctive tokens and phrases from Doing-only titles that are absent from the first ranked title.
     *
     * @param  list<string>  $doingTitles
     * @return list<string>
     */
    private static function doingTitleBleedMarkersVersusFirstRanked(array $doingTitles, string $firstRankedTitleLower): array
    {
        $first = trim($firstRankedTitleLower);
        if ($first === '') {
            return [];
        }

        $generic = self::prioritizeDoingCoachRankedBleedGenericTokens();
        $markerWeights = [];

        foreach ($doingTitles as $rawTitle) {
            $title = trim((string) $rawTitle);
            if ($title === '') {
                continue;
            }

            $codesFound = @preg_match_all('/\b[A-Z]{2,4}\h*\d{2,4}\b/u', $title, $codeMatches);
            if ($codesFound !== false && isset($codeMatches[0]) && $codeMatches[0] !== []) {
                foreach ($codeMatches[0] as $code) {
                    $c = mb_strtolower(trim((string) preg_replace('/\h+/u', ' ', $code)));
                    if ($c !== '' && ! self::mbStrContainsInsensitive($first, $c)) {
                        $markerWeights[$c] = max($markerWeights[$c] ?? 0, mb_strlen($c));
                    }
                }
            }

            $norm = mb_strtolower($title);
            $norm = (string) (preg_replace('/[’\']/u', ' ', $norm) ?? $norm);
            $tokens = preg_split('/[^\pL\pN]+/u', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            for ($i = 0, $max = count($tokens); $i < $max; $i++) {
                $t = (string) $tokens[$i];
                $len = mb_strlen($t);
                if ($len >= 4 && ! isset($generic[$t]) && ! self::mbStrContainsInsensitive($first, $t)) {
                    $markerWeights[$t] = max($markerWeights[$t] ?? 0, $len);
                }
                if (self::prioritizeRankedShortAcademicBleedToken($t) && ! self::mbStrContainsInsensitive($first, $t)) {
                    $markerWeights[$t] = max($markerWeights[$t] ?? 0, $len);
                }
            }

            for ($i = 0, $iMax = count($tokens) - 1; $i < $iMax; $i++) {
                $a = (string) $tokens[$i];
                $b = (string) $tokens[$i + 1];
                if (mb_strlen($a) < 2 || mb_strlen($b) < 2) {
                    continue;
                }

                $bigram = $a.' '.$b;
                if (mb_strlen($bigram) < 6 || mb_strlen($bigram) > 80) {
                    continue;
                }

                if (! self::mbStrContainsInsensitive($first, $bigram)) {
                    $markerWeights[$bigram] = mb_strlen($bigram);
                }
            }
        }

        arsort($markerWeights);

        return array_keys($markerWeights);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private static function prioritizeTitlesBlobLower(array $items): string
    {
        $parts = [];
        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            $t = trim((string) ($row['title'] ?? ''));
            if ($t !== '') {
                $parts[] = mb_strtolower($t);
            }
        }

        return trim(implode(' ', $parts));
    }

    /**
     * @param  list<array<string, mixed>>  $rankedItems
     * @param  list<string>  $doingTitles
     * @return list<string>
     */
    private static function rankedSliceMarkersAbsentFromDoingTitles(array $rankedItems, array $doingTitles): array
    {
        $doingBlob = mb_strtolower(implode("\n", array_map(
            static fn (string $t): string => trim($t),
            array_values(array_filter(
                array_map(static fn (mixed $t): string => trim((string) $t), $doingTitles),
                static fn (string $s): bool => $s !== ''
            ))
        )));

        $generic = self::prioritizeDoingCoachRankedBleedGenericTokens();

        $markerWeights = [];
        foreach ($rankedItems as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $norm = mb_strtolower($title);
            $norm = (string) (preg_replace('/[’\']/u', ' ', $norm) ?? $norm);
            $tokens = preg_split('/[^\pL\pN]+/u', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            for ($i = 0, $max = count($tokens); $i < $max; $i++) {
                $t = (string) $tokens[$i];
                $len = mb_strlen($t);
                if ($len >= 6 && ! isset($generic[$t]) && ! self::mbStrContainsInsensitive($doingBlob, $t)) {
                    $markerWeights[$t] = max($markerWeights[$t] ?? 0, $len);
                }
                if (self::prioritizeRankedShortAcademicBleedToken($t)
                    && ! self::mbStrContainsInsensitive($doingBlob, $t)) {
                    $markerWeights[$t] = max($markerWeights[$t] ?? 0, $len);
                }
            }

            for ($i = 0, $iMax = count($tokens) - 1; $i < $iMax; $i++) {
                $a = (string) $tokens[$i];
                $b = (string) $tokens[$i + 1];
                if (mb_strlen($a) < 4 || mb_strlen($b) < 4) {
                    continue;
                }
                $bigram = $a.' '.$b;
                if (mb_strlen($bigram) < 10 || mb_strlen($bigram) > 72) {
                    continue;
                }
                if (self::mbStrContainsInsensitive($doingBlob, $bigram)) {
                    continue;
                }
                $markerWeights[$bigram] = mb_strlen($bigram);
            }
        }

        arsort($markerWeights);

        return array_keys($markerWeights);
    }

    private static function prioritizeRankedShortAcademicBleedToken(string $normalizedToken): bool
    {
        $t = mb_strtolower($normalizedToken);

        return in_array($t, [
            'notes', 'essay', 'lecture', 'reading', 'response', 'homework', 'midterm', 'assignment',
            'assignments', 'quiz', 'quizzes', 'exam', 'exams',
        ], true);
    }

    /**
     * Markers taken from ranked rows after row #1 that do not appear inside the first title.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private static function secondaryRankedRowMarkersVersusFirst(array $items, string $firstLower): array
    {
        $generic = self::prioritizeReasoningSecondaryRowGenericTokens();
        $markerWeights = [];

        for ($r = 1, $rMax = count($items); $r < $rMax; $r++) {
            $row = $items[$r] ?? null;
            if (! is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $norm = mb_strtolower($title);
            $norm = (string) (preg_replace('/[’\']/u', ' ', $norm) ?? $norm);
            $tokens = preg_split('/[^\pL\pN]+/u', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            for ($i = 0, $max = count($tokens); $i < $max; $i++) {
                $t = (string) $tokens[$i];
                $len = mb_strlen($t);
                if (isset($generic[$t])) {
                    continue;
                }
                if (self::mbStrContainsInsensitive($firstLower, $t)) {
                    continue;
                }
                $isLong = $len >= 6;
                $isShortAcademic = self::prioritizeRankedShortAcademicBleedToken($t);
                if (! $isLong && ! $isShortAcademic) {
                    continue;
                }
                $markerWeights[$t] = max($markerWeights[$t] ?? 0, $len);
            }

            for ($i = 0, $iMax = count($tokens) - 1; $i < $iMax; $i++) {
                $a = (string) $tokens[$i];
                $b = (string) $tokens[$i + 1];
                if (mb_strlen($a) < 4 || mb_strlen($b) < 4) {
                    continue;
                }
                $bigram = $a.' '.$b;
                if (mb_strlen($bigram) < 10 || mb_strlen($bigram) > 72) {
                    continue;
                }
                if (self::mbStrContainsInsensitive($firstLower, $bigram)) {
                    continue;
                }
                $markerWeights[$bigram] = mb_strlen($bigram);
            }
        }

        arsort($markerWeights);

        return array_keys($markerWeights);
    }

    /**
     * @param  list<string>  $markers  longest markers should be listed first for greedy matching
     */
    private static function narrativeSentenceMatchesAnyDoingCoachBleedMarker(string $sentence, array $markers): bool
    {
        foreach ($markers as $marker) {
            $m = trim((string) $marker);
            if ($m === '') {
                continue;
            }
            if (str_contains($m, ' ')) {
                if (mb_stripos($sentence, $m) !== false) {
                    return true;
                }

                continue;
            }
            if (mb_strlen($m) < 4) {
                continue;
            }
            if ((bool) preg_match('/(?<![\pL\pN])'.preg_quote($m, '/').'(?![\pL\pN])/u', $sentence)) {
                return true;
            }
        }

        return false;
    }

    private static function mbStrContainsInsensitive(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return mb_stripos($haystack, $needle) !== false;
    }

    /**
     * @return array<string, true>
     */
    private static function prioritizeDoingCoachRankedBleedGenericTokens(): array
    {
        $words = [
            'about', 'after', 'also', 'already', 'before', 'being', 'busy', 'come', 'comes', 'could',
            'dedicated', 'doing', 'done', 'down', 'even', 'ever', 'feel', 'feeling', 'feelings', 'first',
            'focus', 'fully', 'get', 'gets', 'getting', 'give', 'good', 'great', 'head', 'help', 'helps',
            'here', 'just', 'keep', 'last', 'later', 'like', 'line', 'list', 'long', 'look', 'make',
            'makes', 'more', 'most', 'much', 'need', 'needs', 'next', 'often', 'only', 'onto', 'open',
            'other', 'over', 'pass', 'passes', 'path', 'pile', 'plus', 'prepared', 'quite', 'ready',
            'really', 'same', 'some', 'soon', 'start', 'started', 'still', 'such', 'sure', 'swap', 'take',
            'taken', 'than', 'thank', 'that', 'them', 'then', 'there', 'these', 'they', 'thing', 'things',
            'this', 'those', 'though', 'time', 'times', 'today', 'tomorrow', 'too', 'truly', 'under',
            'until', 'very', 'want', 'wants', 'well', 'were', 'what', 'when', 'where', 'while', 'will',
            'with', 'work', 'working', 'worth', 'would', 'your', 'yours',
        ];

        $map = [];
        foreach ($words as $w) {
            $map[mb_strtolower($w)] = true;
        }

        return $map;
    }

    /**
     * @return array<string, true>
     */
    private static function prioritizeReasoningSecondaryRowGenericTokens(): array
    {
        $words = [
            'after', 'also', 'before', 'being', 'could', 'first', 'fully', 'just', 'keep', 'later',
            'like', 'long', 'make', 'more', 'most', 'much', 'need', 'next', 'only', 'other', 'over',
            'really', 'review', 'same', 'some', 'still', 'such', 'sure', 'take', 'than', 'that', 'them', 'then',
            'there', 'these', 'they', 'this', 'those', 'though', 'time', 'today', 'tomorrow', 'under',
            'until', 'very', 'want', 'well', 'what', 'when', 'where', 'while', 'will', 'with', 'work',
            'would', 'your', 'today\'s',
        ];

        $map = [];
        foreach ($words as $w) {
            $map[mb_strtolower($w)] = true;
        }

        return $map;
    }

    /**
     * When Doing tasks exist alongside a ranked slice, framing must not front-load ITEMS_JSON titles
     * (student sees in-progress block first). Strip offending sentences; if nothing remains, use fallback.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public static function refineFramingWhenDoingCoexistsAvoidRankedTitles(
        string $framing,
        array $items,
        bool $doingCoachRequired,
        ?string $diversifier = null,
    ): string {
        if (! $doingCoachRequired || $items === []) {
            return $framing;
        }

        $f = trim($framing);
        if ($f === '') {
            return self::clampFraming(self::buildPrioritizeFramingDoingFirstIntroFallback($diversifier));
        }

        $patterns = config('task-assistant.listing.prioritize_framing_when_doing_sentence_drop_patterns', []);
        if (! is_array($patterns)) {
            $patterns = [];
        }

        $minKept = (int) config('task-assistant.listing.prioritize_framing_when_doing_min_chars_after_strip', 40);
        $minKept = max(20, min(200, $minKept));

        $titles = [];
        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if (mb_strlen($title) >= 4) {
                $titles[] = $title;
            }
        }

        $parts = self::splitNarrativeSentences($f);
        if ($parts === []) {
            $parts = [$f];
        }

        $kept = [];
        foreach ($parts as $sent) {
            $s = trim((string) $sent);
            if ($s === '') {
                continue;
            }
            $drop = false;
            foreach ($titles as $title) {
                if (mb_stripos($s, $title) !== false) {
                    $drop = true;

                    break;
                }
            }
            if (! $drop && $patterns !== []) {
                foreach ($patterns as $pattern) {
                    if (! is_string($pattern) || $pattern === '') {
                        continue;
                    }
                    if (@preg_match($pattern, $s) === 1) {
                        $drop = true;

                        break;
                    }
                }
            }
            if (! $drop) {
                $kept[] = $s;
            }
        }

        $out = trim(implode(' ', $kept));
        if ($out === '' || mb_strlen($out) < $minKept) {
            return self::clampFraming(self::buildPrioritizeFramingDoingFirstIntroFallback($diversifier));
        }

        return self::clampFraming($out);
    }

    /**
     * Title-safe framing when Doing and ranked rows co-exist (deterministic; varies slightly by diversifier).
     */
    public static function buildPrioritizeFramingDoingFirstIntroFallback(?string $diversifier = null): string
    {
        $n = ($diversifier !== null && $diversifier !== '')
            ? abs(crc32($diversifier)) % 3
            : 0;

        return match ($n) {
            0 => (string) __('You’ve already got tasks underway—worth steadying those before you add more. What’s in motion is listed next, then your most pressing ranked next step.'),
            1 => (string) __('I’d anchor on what you’ve already started; it cuts switching and builds momentum. Your in-progress work is below, followed by the ranked slice when you’re ready for what’s next.'),
            default => (string) __('Start from what you have in motion—those tasks show first below, then the ranked item(s) for what to pick up next.'),
        };
    }

    /**
     * Remove low-value or risky prioritize narrative assumptions (internal metadata only).
     *
     * @param  list<string>|null  $lines
     * @return list<string>|null
     */
    public static function filterPrioritizeAssumptions(?array $lines): ?array
    {
        if ($lines === null || $lines === []) {
            return null;
        }

        $patterns = config('task-assistant.listing.prioritize_assumption_denylist_patterns', []);
        if (! is_array($patterns)) {
            $patterns = [];
        }

        $out = [];
        foreach ($lines as $line) {
            if (! is_string($line)) {
                continue;
            }
            $t = trim($line);
            if ($t === '') {
                continue;
            }
            $drop = false;
            foreach ($patterns as $pattern) {
                if (! is_string($pattern) || $pattern === '') {
                    continue;
                }
                if (@preg_match($pattern, $t) === 1) {
                    $drop = true;

                    break;
                }
            }
            if (! $drop) {
                $out[] = mb_substr($t, 0, 240);
            }
            if (count($out) >= 4) {
                break;
            }
        }

        return $out === [] ? null : array_values($out);
    }

    /**
     * Machine-readable hints for the prioritize narrative LLM (rank variant). Derived from slice rows and titles.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public static function buildPrioritizeNarrativeCoachContextBlock(
        array $items,
        string $prioritizeVariant,
        bool $suppressRowTwoVersusRowOneHint = false,
    ): string {
        $v = trim($prioritizeVariant);
        if ($v === '') {
            $v = 'rank';
        }

        $multi = count($items) > 1;
        $hasEvent = false;
        foreach ($items as $it) {
            if (is_array($it) && strtolower(trim((string) ($it['entity_type'] ?? ''))) === 'event') {
                $hasEvent = true;

                break;
            }
        }

        $firstTitle = '';
        if (isset($items[0]) && is_array($items[0])) {
            $firstTitle = trim((string) ($items[0]['title'] ?? ''));
        }

        $largeBlock = $firstTitle !== ''
            && (bool) preg_match('/\b(impossible|all[-\s]?day|\d+\s*h(?:\s|[\p{L}]|$)|mega|marathon)\b/iu', $firstTitle);

        $multiRowHint = $suppressRowTwoVersusRowOneHint
            ? ''
            : 'If MULTI_ROW is true, include one short phrase explaining how row 2 relates to row 1 (for example a time-bound event vs a later-due task) using only entity_type, titles, and due fields from ITEMS_JSON—no invented clock times.'."\n";

        return
            "COACH_CONTEXT (internal hints; do not paste these labels into student-visible text):\n".
            "PRIORITIZE_VARIANT: {$v}\n".
            'MULTI_ROW: '.($multi ? 'true' : 'false')."\n".
            'SLICE_INCLUDES_EVENT: '.($hasEvent ? 'true' : 'false')."\n".
            'TOP_TITLE_SUGGESTS_LARGE_BLOCK: '.($largeBlock ? 'true' : 'false')."\n".
            'If TOP_TITLE_SUGGESTS_LARGE_BLOCK is true, add one practical smaller-first-step suggestion (timeboxed) in framing OR reasoning (before next_options)—not both—without inventing durations not present in ITEMS_JSON.'."\n".
            $multiRowHint.
            'Avoid rhetorical “Today,” as an opener unless a row’s due_phrase supports calendar-today language.'."\n".
            'Do not restate overdue/complex/priority facts in reasoning if framing or filter_interpretation already stated them; reasoning appears before next_options and should carry why-first and/or one micro-step. Do not repeat next_options scheduling lines in reasoning.';
    }

    /**
     * Formatter-only bridge between intro (framing) and the Doing coach paragraph.
     * Omitted when the coach line already signals in-progress work (avoids repeating the same setup).
     */
    public static function shouldEmitPrioritizeFormatterBridgeBeforeDoingCoach(?string $doingProgressCoach): bool
    {
        $c = mb_strtolower(trim((string) $doingProgressCoach));

        if ($c === '') {
            return false;
        }

        return ! str_contains($c, 'in progress');
    }

    /**
     * Formatter-only bridge after intro and before the Doing coach paragraph and in-progress title list.
     */
    public static function prioritizeFormatterBridgeBeforeDoingCoach(): string
    {
        return (string) __('You’ve also got work already in progress—worth a quick look before you pile on more.');
    }

    /**
     * Formatter-only bridge after the doing coach and before the numbered list (Doing-status tasks are excluded from this slice).
     *
     * @param  int  $listedToDoCount  Displayed ranked rows (not Doing tasks).
     */
    public static function prioritizeFormatterBridgeAfterDoingCoach(int $listedToDoCount): string
    {
        if ($listedToDoCount <= 0) {
            return '';
        }

        return $listedToDoCount === 1
            ? (string) __('When you’re ready for a new focus on top of what’s already in motion, I’d start with what you see below—it reflects the sharpest need first.')
            : (string) __('When you’re ready to line up what comes next after what’s already underway, I’d work through the list below in order—it runs from most pressing to less urgent.');
    }

    /**
     * Remove student-"discovery" meta phrasing and odd "we" voice from prioritize framing.
     * Drops whole sentences that match; may return empty so callers can fall back.
     */
    public static function sanitizePrioritizeFramingMetaVoice(string $framing, int $listedItemCount): string
    {
        $f = trim($framing);
        if ($f === '') {
            return $f;
        }

        $f = preg_replace('/\bour attention\b/iu', 'your attention', $f) ?? $f;
        $f = preg_replace('/\bour focus\b/iu', 'your focus', $f) ?? $f;
        $f = preg_replace('/\bwe should\b/iu', 'you could', $f) ?? $f;

        $parts = preg_split('/(?<=[.!?])\s+/u', $f, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($parts) || $parts === []) {
            $parts = [$f];
        }

        $kept = [];
        foreach ($parts as $part) {
            $sentence = trim((string) $part);
            if ($sentence === '') {
                continue;
            }
            if (self::isPrioritizeFramingDiscoveryMetaSentence($sentence)) {
                continue;
            }
            $kept[] = $sentence;
        }

        $f = trim(implode(' ', $kept));

        $f = preg_replace('/\bThis first (task|event|project)\b/iu', 'This $1', $f) ?? $f;
        $f = preg_replace('/\bThat first (task|event|project)\b/iu', 'That $1', $f) ?? $f;

        if ($listedItemCount <= 1) {
            $f = preg_replace('/\bthese next (?:items|tasks|priorities)\b/iu', 'this next step', $f) ?? $f;
        }

        return trim($f);
    }

    private static function isPrioritizeFramingDiscoveryMetaSentence(string $sentence): bool
    {
        $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $sentence) ?? $sentence));
        if ($s === '') {
            return false;
        }

        if (preg_match('/^i understand (?:that )?you(?:\'ve| have)?\s+(found|discovered)\b/', $s) === 1) {
            return true;
        }

        if (preg_match('/^you(?:\'ve| have)\s+(found|discovered)\b/', $s) === 1
            && preg_match('/\b(on your list|your list|your tasks)\b/', $s) === 1) {
            return true;
        }

        if (preg_match('/\byou(?:\'ve| have)?\s+(found|discovered)\s+(?:one\s+|a\s+|an\s+)?(?:top\s+)?priority\b/', $s) === 1) {
            return true;
        }

        return false;
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
     * Keeps prioritize narrative reasoning within max length.
     */
    public static function clampPrioritizeReasoning(string $text): string
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
        // next_options: room for a warm coach-style scheduling offer without truncation.
        return max(1, min(320, self::maxReasoningChars()));
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

        return self::clampPrioritizeReasoning(trim($out));
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

    public static function clampPrioritizeSuggestedGuidance(string $text): string
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
        return __('Pick one task from this list to start so your first step stays light. If you share what you want to focus on, I can narrow it further or help map what to tackle first.');
    }

    public static function reasoningWhenEmpty(): string
    {
        return __('I could not add a custom explanation this time, but this order still follows your usual student-first ranking so you have a clear next step.');
    }

    public static function defaultRankingMethodSummary(): string
    {
        return 'I put urgent work first, then priority and effort, so your next move is both important and realistic.';
    }

    public static function buildBalancedPrioritizeRankingMethodSummary(): string
    {
        return self::defaultRankingMethodSummary();
    }

    public static function defaultOrderingRationaleLineBody(): string
    {
        return 'This stays high because it is one of your clearest next moves right now.';
    }

    public static function buildPrioritizeOrderingLine(int $rank, string $title, string $reason): string
    {
        $safeRank = max(1, $rank);
        $safeTitle = trim($title);
        if ($safeTitle === '') {
            $safeTitle = 'Item';
        }
        $safeReason = trim($reason);
        if ($safeReason === '') {
            $safeReason = self::defaultOrderingRationaleLineBody();
        }

        return '#'.$safeRank.' '.$safeTitle.': '.$safeReason;
    }

    public static function buildDeterministicPrioritizeFraming(int $count, bool $ambiguous): string
    {
        if ($count === 0) {
            return (string) __('Nothing matched that request yet—try widening filters or adding a task.');
        }

        if ($ambiguous) {
            return (string) __('Here are your strongest next steps from what is currently visible in your list.');
        }

        return $count === 1
            ? (string) __('Here is the strongest next step for this request.')
            : (string) __('Here are the strongest next steps for this request.');
    }

    public static function buildDeterministicPrioritizeNextOptionsLine(int $itemsCount, bool $hasMoreUnseen): string
    {
        if ($itemsCount <= 1) {
            return self::clampNextField('If you want, I can place this top task later today, tomorrow, or later this week.');
        }

        if (! $hasMoreUnseen) {
            return self::clampNextField('This covers the key items for your request. If you want, I can place them later today, tomorrow, or later this week.');
        }

        return self::clampNextField('If you want, I can place these ranked tasks later today, tomorrow, or later this week.');
    }

    /**
     * @return list<string>
     */
    public static function buildDeterministicPrioritizeNextOptionChips(int $itemsCount): array
    {
        if ($itemsCount <= 1) {
            return [
                self::clampNextOptionChipText('Schedule that task for later today'),
                self::clampNextOptionChipText('Schedule that task for tomorrow'),
            ];
        }

        return [
            self::clampNextOptionChipText('Schedule those tasks for later today'),
            self::clampNextOptionChipText('Schedule those tasks for tomorrow'),
            self::clampNextOptionChipText('Schedule only the top task for later'),
        ];
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

    /**
     * Remove reasoning sentences that claim the top-ranked task is "about" a topic whose significant words
     * do not appear in row #1's title (stops cross-task bleed, e.g. Flexbox Doing work → "web design" on a quiz task).
     *
     * @param  list<array<string, mixed>>  $items
     */
    public static function stripReasoningSentencesWithUngroundedAboutClaims(string $reasoning, array $items): string
    {
        $reasoning = trim($reasoning);
        if ($reasoning === '' || $items === []) {
            return $reasoning;
        }

        $first = $items[0] ?? null;
        if (! is_array($first)) {
            return $reasoning;
        }

        $title = trim((string) ($first['title'] ?? ''));
        if ($title === '') {
            return $reasoning;
        }

        $titleLower = mb_strtolower($title);
        $allow = self::prioritizeReasoningAboutClauseTokenAllowlist();
        $sentences = self::splitNarrativeSentences($reasoning);
        if ($sentences === []) {
            return $reasoning;
        }

        $kept = [];
        foreach ($sentences as $sent) {
            $s = trim((string) $sent);
            if ($s === '') {
                continue;
            }
            if (self::reasoningSentenceFailsAboutClauseGrounding($s, $titleLower, $allow)) {
                continue;
            }
            $kept[] = $s;
        }

        return trim(implode(' ', $kept));
    }

    /**
     * @return array<string, true>
     */
    private static function prioritizeReasoningAboutClauseTokenAllowlist(): array
    {
        $words = [
            'about', 'also', 'already', 'after', 'again', 'balance', 'before', 'being', 'best', 'both', 'break',
            'calm', 'clear', 'comes', 'could', 'deadlines', 'down', 'energy', 'even', 'ever', 'feel', 'first',
            'focus', 'from', 'good', 'great', 'habit', 'habits', 'head', 'help', 'here', 'into', 'it’s', 'its',
            'just', 'keep', 'kind', 'last', 'later', 'lets', 'like', 'line', 'list', 'make', 'more', 'most',
            'much', 'momentum', 'need', 'next', 'once', 'only', 'other', 'over', 'overwhelm', 'pressure',
            'priority', 'progress', 'puts', 'same', 'sessions', 'session', 'sets', 'show', 'slice', 'smaller',
            'some', 'step', 'steps', 'stress', 'such', 'sure', 'take', 'than', 'that', 'them', 'then', 'there',
            'these', 'they', 'this', 'those', 'though', 'time', 'timing', 'today', 'truly', 'very', 'want',
            'well', 'were', 'what', 'when', 'where', 'while', 'will', 'with', 'without', 'work', 'your',
            'you’re', 'still', 'upcoming',
        ];

        $map = [];
        foreach ($words as $w) {
            $map[mb_strtolower($w)] = true;
        }

        return $map;
    }

    /**
     * @param  array<string, true>  $allowLower
     */
    private static function reasoningSentenceFailsAboutClauseGrounding(string $sentence, string $titleLower, array $allowLower): bool
    {
        if (preg_match('/\babout\b/iu', $sentence) !== 1) {
            return false;
        }

        $lower = mb_strtolower($sentence);
        if (! str_contains($lower, 'about')) {
            return false;
        }

        $pos = mb_stripos($sentence, 'about');
        if ($pos === false) {
            return false;
        }

        $after = mb_substr($sentence, $pos + mb_strlen('about'));
        $after = trim((string) (preg_replace('/^[\s:;,.\-–—]+/u', '', $after) ?? $after));
        if ($after === '') {
            return false;
        }

        $clause = trim((string) (preg_replace('/[.!?]+$/u', '', $after) ?? $after));
        $clause = trim((string) (preg_replace('/[—–].*$/u', '', $clause) ?? $clause));
        $tokens = preg_split('/[^\pL\pN]+/u', mb_strtolower($clause), -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($tokens) || $tokens === []) {
            return false;
        }

        $significant = 0;
        foreach ($tokens as $tok) {
            if (mb_strlen($tok) < 4) {
                continue;
            }
            $significant++;
            if (isset($allowLower[$tok])) {
                continue;
            }
            if (mb_strpos($titleLower, $tok) !== false) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Token Jaccard similarity on normalized word tokens (for cross-field redundancy).
     */
    public static function narrativeTokenJaccardSimilarity(string $a, string $b): float
    {
        $aNorm = self::narrativeNormalizeForCompare($a);
        $bNorm = self::narrativeNormalizeForCompare($b);
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
     * Drop filter_interpretation when it mostly repeats framing (small models often echo).
     */
    public static function dedupePrioritizeFilterVersusFraming(?string $filterInterpretation, string $framing, float $wholeJaccardDrop = 0.78): ?string
    {
        if ($filterInterpretation === null) {
            return null;
        }
        $fi = trim($filterInterpretation);
        if ($fi === '') {
            return null;
        }
        $framingTrim = trim($framing);
        if ($framingTrim === '') {
            return $fi;
        }
        if (self::narrativeTokenJaccardSimilarity($fi, $framingTrim) >= $wholeJaccardDrop) {
            return null;
        }
        $fin = self::narrativeNormalizeForCompare($fi);
        $frn = self::narrativeNormalizeForCompare($framingTrim);
        if (mb_strlen($fin) >= 20 && str_contains($frn, $fin)) {
            return null;
        }

        return $fi;
    }

    /**
     * Remove reasoning sentences that largely repeat acknowledgment, framing, filter, or next_options (Hermes/small-model slop).
     *
     * Compares each reasoning sentence to **individual sentences** within prior fields (not only whole paragraphs),
     * so short echo lines like “It’s overdue and quite complex.” are dropped when framing already said it.
     * next_options is included so reasoning does not echo the same scheduling lines the following paragraph will use.
     *
     * @param  list<array<string, mixed>>|null  $items  Optional top-ranked rows; enables title/status echo guard.
     * @return string Non-empty; falls back to {@see reasoningWhenEmpty()} if everything would be stripped.
     */
    public static function dedupePrioritizeReasoningVersusPriorFields(
        string $reasoning,
        ?string $acknowledgment,
        string $framing,
        ?string $filterInterpretation,
        ?array $items = null,
        ?float $sentenceJaccardDrop = null,
        ?string $nextOptions = null,
    ): string {
        $threshold = $sentenceJaccardDrop ?? (float) config(
            'task-assistant.listing.prioritize_reasoning_dedupe_sentence_jaccard',
            0.5
        );
        $threshold = max(0.15, min(0.95, $threshold));

        $statusOverlapJaccard = (float) config(
            'task-assistant.listing.prioritize_reasoning_framing_status_overlap_jaccard',
            0.4
        );
        $statusOverlapJaccard = max(0.2, min(0.85, $statusOverlapJaccard));

        $reasoning = trim($reasoning);
        if ($reasoning === '') {
            return self::reasoningWhenEmpty();
        }

        $corpus = self::buildPrioritizeReasoningCorpus($acknowledgment, $framing, $filterInterpretation, $nextOptions);

        if ($corpus === []) {
            return self::collapseAdjacentSimilarNarrativeSentences($reasoning);
        }

        $framingCorpusOnly = self::buildPrioritizeReasoningCorpus(null, $framing, null);

        $sentences = self::splitNarrativeSentences($reasoning);
        if ($sentences === []) {
            return self::collapseAdjacentSimilarNarrativeSentences($reasoning);
        }

        $kept = [];
        foreach ($sentences as $sent) {
            $sentTrim = trim($sent);
            if ($sentTrim === '') {
                continue;
            }
            $drop = false;
            foreach ($corpus as $block) {
                if (self::narrativeTokenJaccardSimilarity($sentTrim, $block) >= $threshold) {
                    $drop = true;
                    break;
                }
                $sn = self::narrativeNormalizeForCompare($sentTrim);
                $bn = self::narrativeNormalizeForCompare($block);
                if (mb_strlen($sn) >= 28 && mb_strlen($bn) >= 28 && str_contains($bn, $sn)) {
                    $drop = true;
                    break;
                }
            }
            if (! $drop && is_array($items) && $items !== []) {
                $drop = self::shouldDropReasoningSentenceAsFramingStatusEcho($sentTrim, $framingCorpusOnly, $items);
            }
            if (! $drop && is_array($items) && $items !== []) {
                $drop = self::shouldDropReasoningSentenceAsFramingStatusOverlap(
                    $sentTrim,
                    trim($framing),
                    $items,
                    $statusOverlapJaccard
                );
            }
            if (! $drop) {
                $kept[] = $sentTrim;
            }
        }

        $out = trim(implode(' ', $kept));
        if ($out === '') {
            return self::reasoningWhenEmpty();
        }

        return self::collapseAdjacentSimilarNarrativeSentences($out);
    }

    /**
     * Drop reasoning sentences that restate framing’s overdue/complex story for row #1 when wording is still near framing.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private static function shouldDropReasoningSentenceAsFramingStatusOverlap(
        string $reasoningSentence,
        string $framing,
        array $items,
        float $statusOverlapJaccard,
    ): bool {
        $framingTrim = trim($framing);
        if ($framingTrim === '' || $items === []) {
            return false;
        }

        $first = $items[0] ?? null;
        if (! is_array($first)) {
            return false;
        }

        $title = trim((string) ($first['title'] ?? ''));
        if (mb_strlen($title) < 3) {
            return false;
        }

        $rLower = mb_strtolower($reasoningSentence);
        if (! self::prioritizeSentenceHasStatusKeywords($rLower)) {
            return false;
        }

        $tLower = mb_strtolower($title);
        $mentionsTitle = mb_stripos($rLower, $tLower) !== false
            || mb_stripos($reasoningSentence, $title) !== false;

        if (! $mentionsTitle) {
            return false;
        }

        return self::narrativeTokenJaccardSimilarity($reasoningSentence, $framingTrim) >= $statusOverlapJaccard;
    }

    /**
     * Whole blocks plus per-sentence splits for cross-field dedupe (unique strings).
     * Include next_options so the reasoning paragraph does not paraphrase the scheduling
     * paragraph that follows it.
     *
     * @return list<string>
     */
    private static function buildPrioritizeReasoningCorpus(
        ?string $acknowledgment,
        string $framing,
        ?string $filterInterpretation,
        ?string $nextOptions = null,
    ): array {
        $seen = [];
        $out = [];

        foreach ([$acknowledgment, $framing, $filterInterpretation, $nextOptions] as $block) {
            if ($block === null) {
                continue;
            }
            $t = trim((string) $block);
            if ($t === '') {
                continue;
            }
            if (! isset($seen[$t])) {
                $seen[$t] = true;
                $out[] = $t;
            }
            foreach (self::splitNarrativeSentences($t) as $sent) {
                $s = trim($sent);
                if ($s === '' || mb_strlen($s) < 8) {
                    continue;
                }
                if (! isset($seen[$s])) {
                    $seen[$s] = true;
                    $out[] = $s;
                }
            }
        }

        return $out;
    }

    /**
     * Drop a reasoning sentence that repeats framing’s status story for the top row without adding a coach action.
     *
     * @param  list<string>  $framingCorpusSentences
     * @param  list<array<string, mixed>>  $items
     */
    private static function shouldDropReasoningSentenceAsFramingStatusEcho(
        string $reasoningSentence,
        array $framingCorpusSentences,
        array $items,
    ): bool {
        $first = $items[0] ?? null;
        if (! is_array($first)) {
            return false;
        }

        $title = trim((string) ($first['title'] ?? ''));
        if (mb_strlen($title) < 3) {
            return false;
        }

        if (self::prioritizeReasoningSentenceAddsCoachAction($reasoningSentence)) {
            return false;
        }

        $rLower = mb_strtolower($reasoningSentence);
        $tLower = mb_strtolower($title);
        if (mb_stripos($rLower, $tLower) === false && mb_stripos($reasoningSentence, $title) === false) {
            return false;
        }

        if (! self::prioritizeSentenceHasStatusKeywords($rLower)) {
            return false;
        }

        $framingJoined = trim(implode(' ', $framingCorpusSentences));
        if ($framingJoined === '') {
            return false;
        }

        $fLower = mb_strtolower($framingJoined);
        if (mb_stripos($fLower, $tLower) === false && mb_stripos($framingJoined, $title) === false) {
            return false;
        }

        if (! self::prioritizeSentenceHasStatusKeywords($fLower)) {
            return false;
        }

        foreach ($framingCorpusSentences as $fs) {
            $fsTrim = trim($fs);
            if ($fsTrim === '') {
                continue;
            }
            if (self::narrativeTokenJaccardSimilarity($reasoningSentence, $fsTrim) >= 0.42) {
                return true;
            }
        }

        return self::narrativeTokenJaccardSimilarity($reasoningSentence, $framingJoined) >= 0.38;
    }

    /**
     * @param  non-empty-string  $lower
     */
    private static function prioritizeSentenceHasStatusKeywords(string $lower): bool
    {
        foreach (['overdue', 'complex', 'urgent', 'due today', 'due tomorrow'] as $kw) {
            if (str_contains($lower, $kw)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Heuristic: sentence suggests a concrete next step or habit (English stems).
     */
    private static function prioritizeReasoningSentenceAddsCoachAction(string $sentence): bool
    {
        $lower = mb_strtolower($sentence);

        return (bool) preg_match(
            '/\b(try|start|split|break|chunk|plan|schedule|focus|tackle|step|minute|block|smaller|shorter|first|next|open|finish|close|work|set|timer|pomodoro|review|draft|outline|paragraph)\b/u',
            $lower
        );
    }

    /**
     * When next_options mostly repeats framing or reasoning, fall back to a neutral scheduling line.
     */
    public static function dedupePrioritizeNextVersusPriorFields(
        string $nextOptions,
        string $framing,
        string $reasoning,
        int $itemsCount,
        float $wholeJaccardDrop = 0.68,
        float $sentenceJaccardDrop = 0.62,
    ): string {
        $next = trim($nextOptions);
        if ($next === '') {
            return $next;
        }
        $blocks = array_values(array_filter([trim($framing), trim($reasoning)], static fn (string $s): bool => $s !== ''));
        foreach ($blocks as $block) {
            if (self::narrativeTokenJaccardSimilarity($next, $block) >= $wholeJaccardDrop) {
                return $itemsCount === 1
                    ? 'If you want, I can help schedule this next step.'
                    : 'If you want, I can help schedule these next steps.';
            }
        }

        $sentences = self::splitNarrativeSentences($next);
        foreach ($sentences as $sent) {
            $sentTrim = trim($sent);
            if ($sentTrim === '') {
                continue;
            }
            foreach ($blocks as $block) {
                if (self::narrativeTokenJaccardSimilarity($sentTrim, $block) >= $sentenceJaccardDrop) {
                    return $itemsCount === 1
                        ? 'If you want, I can help schedule this next step.'
                        : 'If you want, I can help schedule these next steps.';
                }
            }
        }

        return $next;
    }

    /**
     * @return list<string>
     */
    private static function splitNarrativeSentences(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $parts = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($parts) || $parts === []) {
            return [$text];
        }

        return array_values(array_filter(
            array_map(static fn (string $s): string => trim($s), $parts),
            static fn (string $s): bool => $s !== ''
        ));
    }

    private static function narrativeNormalizeForCompare(string $text): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_strtolower($t);
    }

    private static function collapseAdjacentSimilarNarrativeSentences(string $text, float $threshold = 0.88): string
    {
        $sents = self::splitNarrativeSentences($text);
        if (count($sents) < 2) {
            return $text;
        }
        $out = [$sents[0]];
        for ($i = 1, $max = count($sents); $i < $max; $i++) {
            $prev = $out[array_key_last($out)];
            if (self::narrativeTokenJaccardSimilarity($sents[$i], $prev) >= $threshold) {
                continue;
            }
            $out[] = $sents[$i];
        }

        return trim(implode(' ', $out));
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
