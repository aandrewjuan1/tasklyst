<?php

namespace App\Services\LLM\Scheduling;

final class ScheduleEditTargetResolver
{
    public function __construct(private readonly ScheduleEditLexicon $lexicon) {}

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     * @param  list<string>  $lastReferencedProposalUuids
     * @return array{
     *   index: int|null,
     *   proposal_uuid: string|null,
     *   ambiguous: bool,
     *   reason: string|null,
     *   confidence: string,
     *   candidate_titles: list<string>
     * }
     */
    public function resolvePrimaryTarget(
        string $normalizedMessage,
        array $proposals,
        array $lastReferencedProposalUuids = [],
    ): array {
        $count = count($proposals);
        if ($count < 1) {
            return ['index' => null, 'proposal_uuid' => null, 'ambiguous' => true, 'reason' => 'There are no editable schedule items yet.', 'confidence' => 'low', 'candidate_titles' => []];
        }

        if ($count === 1 && (
            $this->lexicon->hasAmbiguousPronoun($normalizedMessage)
            || $this->hasSingleTargetTemporalRefinementCue($normalizedMessage)
        )) {
            return $this->resultFromIndex($proposals, 0, false, null, 'high');
        }

        $uniqueLastUuids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $u): string => trim((string) $u), $lastReferencedProposalUuids),
            static fn (string $u): bool => $u !== ''
        )));

        if ($this->lexicon->hasAmbiguousPronoun($normalizedMessage) && count($uniqueLastUuids) === 1) {
            $needle = $uniqueLastUuids[0];
            foreach ($proposals as $i => $proposal) {
                if (! is_array($proposal)) {
                    continue;
                }
                $rowUuid = trim((string) ($proposal['proposal_uuid'] ?? $proposal['proposal_id'] ?? ''));
                if ($rowUuid !== '' && $rowUuid === $needle) {
                    return $this->resultFromIndex($proposals, (int) $i, false, null, 'high');
                }
            }
        }

        $positional = $this->resolveLeftmostPositionalTarget($normalizedMessage, $proposals);
        if ($positional !== null) {
            return $positional;
        }

        $matched = [];
        foreach ($proposals as $i => $proposal) {
            $title = mb_strtolower(trim((string) ($proposal['title'] ?? '')));
            if ($title === '') {
                continue;
            }
            $tokens = preg_split('/\s+/u', $title) ?: [];
            foreach ($tokens as $token) {
                $token = trim($token, " \t\n\r\0\x0B,.;:!?()[]{}'\"");
                if (mb_strlen($token) < 4) {
                    continue;
                }
                if (preg_match('/\b'.preg_quote($token, '/').'\b/u', $normalizedMessage) === 1) {
                    $matched[] = $i;
                    break;
                }
            }
        }

        $matched = array_values(array_unique($matched));
        if (count($matched) === 1) {
            return $this->resultFromIndex($proposals, $matched[0], false, null, 'high');
        }
        if (count($matched) > 1) {
            return ['index' => null, 'proposal_uuid' => null, 'ambiguous' => true, 'reason' => 'I found multiple matching items. Please say which one to edit.', 'confidence' => 'low', 'candidate_titles' => $this->topCandidateTitles($proposals, $matched)];
        }

        if ($count > 1 && $this->isTemporalRefinementWithoutTargetSignal($normalizedMessage)) {
            return [
                'index' => null,
                'proposal_uuid' => null,
                'ambiguous' => true,
                'reason' => 'I found multiple scheduled items. Tell me which one to change (first, second, last, #number, or title).',
                'confidence' => 'low',
                'candidate_titles' => $this->topCandidateTitles($proposals),
            ];
        }

        if ($this->lexicon->hasAmbiguousPronoun($normalizedMessage)) {
            return ['index' => null, 'proposal_uuid' => null, 'ambiguous' => true, 'reason' => 'Please tell me which listed item to edit (first, second, last, or by title).', 'confidence' => 'low', 'candidate_titles' => $this->topCandidateTitles($proposals)];
        }

        return ['index' => null, 'proposal_uuid' => null, 'ambiguous' => true, 'reason' => 'Please specify which listed item to edit (first, second, last, or by title).', 'confidence' => 'low', 'candidate_titles' => $this->topCandidateTitles($proposals)];
    }

    /**
     * When several ordinals or positional cues appear, prefer the **leftmost** mention in the message
     * (byte offset) instead of a fixed map iteration order.
     *
     * @param  array<int, array<string, mixed>>  $proposals
     * @return array{
     *   index: int|null,
     *   proposal_uuid: string|null,
     *   ambiguous: bool,
     *   reason: string|null,
     *   confidence: string,
     *   candidate_titles: list<string>
     * }|null
     */
    private function resolveLeftmostPositionalTarget(string $normalizedMessage, array $proposals): ?array
    {
        $count = count($proposals);
        if ($count < 1) {
            return null;
        }

        /** @var list<array{off: int, idx: int, conf: string}> $candidates */
        $candidates = [];

        $push = function (int $off, int $idx, string $conf) use (&$candidates, $count): void {
            if ($idx >= 0 && $idx < $count && $off >= 0) {
                $candidates[] = ['off' => $off, 'idx' => $idx, 'conf' => $conf];
            }
        };

        if (preg_match('/\bitem\s*#?(\d+)\b/u', $normalizedMessage, $m, PREG_OFFSET_CAPTURE) === 1) {
            $push((int) $m[0][1], (int) $m[1][0] - 1, 'high');
        }
        if (preg_match('/\b(?:task|event|project)\s*#?(\d+)\b/u', $normalizedMessage, $m, PREG_OFFSET_CAPTURE) === 1) {
            $push((int) $m[0][1], (int) $m[1][0] - 1, 'high');
        }
        if (preg_match('/\btop\s*#?\s*(\d+)\b/u', $normalizedMessage, $m, PREG_OFFSET_CAPTURE) === 1) {
            $push((int) $m[0][1], (int) $m[1][0] - 1, 'high');
        }
        if (preg_match('/\brank(?:ed)?\s*#?\s*(\d+)\b/u', $normalizedMessage, $m, PREG_OFFSET_CAPTURE) === 1) {
            $push((int) $m[0][1], (int) $m[1][0] - 1, 'high');
        }
        if (preg_match('/\b(?:line|row|slot)\s*#?\s*(\d+)\b/u', $normalizedMessage, $m, PREG_OFFSET_CAPTURE) === 1) {
            $push((int) $m[0][1], (int) $m[1][0] - 1, 'high');
        }
        if (preg_match('/\b(?:number|no\.|nr\.)\s*(\d+)\b/u', $normalizedMessage, $m, PREG_OFFSET_CAPTURE) === 1) {
            $push((int) $m[0][1], (int) $m[1][0] - 1, 'high');
        }

        if (preg_match('/\btop\s+(one|first|two|second|three|third)\b/u', $normalizedMessage, $m, PREG_OFFSET_CAPTURE) === 1) {
            $word = (string) $m[1][0];
            $map = [
                'one' => 0, 'first' => 0, 'two' => 1, 'second' => 1, 'three' => 2, 'third' => 2,
            ];
            if (isset($map[$word])) {
                $push((int) $m[0][1], min($map[$word], $count - 1), 'high');
            }
        }

        if (preg_match('/(?:^|[\s,;])#([1-9]\d{0,2})\b/u', $normalizedMessage, $m, PREG_OFFSET_CAPTURE) === 1) {
            $push((int) $m[0][1], (int) $m[1][0] - 1, 'high');
        }
        if (preg_match('/\b(\d+)(?:st|nd|rd|th)\b/u', $normalizedMessage, $m, PREG_OFFSET_CAPTURE) === 1) {
            $push((int) $m[0][1], (int) $m[1][0] - 1, 'high');
        }

        foreach ($this->lexicon->ordinalMap() as $token => $idx) {
            if (preg_match('/\b'.preg_quote($token, '/').'\b/u', $normalizedMessage, $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            $push((int) $m[0][1], min($idx, $count - 1), 'medium');
        }

        if (preg_match('/\blast\b/u', $normalizedMessage, $m, PREG_OFFSET_CAPTURE) === 1) {
            $push((int) $m[0][1], max(0, $count - 1), 'medium');
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $a, array $b): int {
            if ($a['off'] !== $b['off']) {
                return $a['off'] <=> $b['off'];
            }

            $rank = ['high' => 0, 'medium' => 1];

            return ($rank[$a['conf']] ?? 2) <=> ($rank[$b['conf']] ?? 2);
        });

        $pick = $candidates[0];

        return $this->resultFromIndex($proposals, $pick['idx'], false, null, $pick['conf']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     * @return array{
     *   index: int|null,
     *   proposal_uuid: string|null,
     *   ambiguous: bool,
     *   reason: string|null,
     *   confidence: string,
     *   candidate_titles: list<string>
     * }
     */
    private function resultFromIndex(array $proposals, int $idx, bool $ambiguous, ?string $reason, string $confidence): array
    {
        $proposal = $proposals[$idx] ?? [];
        $uuid = is_array($proposal)
            ? trim((string) ($proposal['proposal_uuid'] ?? $proposal['proposal_id'] ?? ''))
            : '';

        return [
            'index' => $idx,
            'proposal_uuid' => $uuid !== '' ? $uuid : null,
            'ambiguous' => $ambiguous,
            'reason' => $reason,
            'confidence' => $confidence,
            'candidate_titles' => $this->topCandidateTitles($proposals),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     * @param  array<int, int>|null  $indices
     * @return list<string>
     */
    private function topCandidateTitles(array $proposals, ?array $indices = null): array
    {
        $titles = [];
        $source = $indices ?? array_keys($proposals);
        foreach ($source as $index) {
            $proposal = $proposals[$index] ?? null;
            if (! is_array($proposal)) {
                continue;
            }
            $title = trim((string) ($proposal['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $titles[] = $title;
            if (count($titles) >= 3) {
                break;
            }
        }

        return $titles;
    }

    private function hasSingleTargetTemporalRefinementCue(string $normalizedMessage): bool
    {
        return preg_match(
            '/\b(later|earlier|today|tomorrow|tmrw|tonight|next week|morning|afternoon|evening|night|after lunch|after dinner|onward|onwards)\b/u',
            $normalizedMessage
        ) === 1
            || preg_match('/\b(at\s+)?\d{1,2}(:\d{2})?\s*(am|pm)\b/u', $normalizedMessage) === 1
            || preg_match('/\b\d+\s*(min|mins|minute|minutes)\b/u', $normalizedMessage) === 1;
    }

    private function isTemporalRefinementWithoutTargetSignal(string $normalizedMessage): bool
    {
        $hasTemporalCue = $this->hasSingleTargetTemporalRefinementCue($normalizedMessage);
        if (! $hasTemporalCue) {
            return false;
        }

        $hasTargetSignal = preg_match(
            '/\b(first|second|third|last|\d+(?:st|nd|rd|th)|#\d+|item\s*#?\d+|task\s*#?\d+|top\s+\d+|ranked\s*#?\d+|line\s*#?\d+|row\s*#?\d+|slot\s*#?\d+)\b/u',
            $normalizedMessage
        ) === 1;

        if ($hasTargetSignal) {
            return false;
        }

        $hasMeaningfulTitleToken = $this->containsLikelyTitleToken($normalizedMessage);

        return ! $hasMeaningfulTitleToken;
    }

    private function containsLikelyTitleToken(string $normalizedMessage): bool
    {
        $tokens = preg_split('/[^\pL\pN]+/u', mb_strtolower($normalizedMessage), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return false;
        }

        $temporalStopwords = [
            'later', 'earlier', 'today', 'tomorrow', 'tmrw', 'tonight', 'next', 'week',
            'morning', 'afternoon', 'evening', 'night', 'after', 'lunch', 'dinner',
            'at', 'am', 'pm', 'onward', 'onwards', 'move', 'shift', 'change', 'set',
            'edit', 'reschedule', 'adjust', 'it', 'this', 'that', 'one',
        ];

        foreach ($tokens as $token) {
            if (mb_strlen($token) < 4) {
                continue;
            }
            if (in_array($token, $temporalStopwords, true)) {
                continue;
            }

            return true;
        }

        return false;
    }
}
