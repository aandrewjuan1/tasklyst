<?php

namespace App\Services\LLM\Scheduling;

final class ScheduleEditTargetResolver
{
    public function __construct(private readonly ScheduleEditLexicon $lexicon) {}

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
    public function resolvePrimaryTarget(string $normalizedMessage, array $proposals): array
    {
        $count = count($proposals);
        if ($count < 1) {
            return ['index' => null, 'proposal_uuid' => null, 'ambiguous' => true, 'reason' => 'There are no editable schedule items yet.', 'confidence' => 'low', 'candidate_titles' => []];
        }

        if (preg_match('/\bitem\s*#?(\d+)\b/u', $normalizedMessage, $m) === 1) {
            $idx = (int) $m[1] - 1;
            if ($idx >= 0 && $idx < $count) {
                return $this->resultFromIndex($proposals, $idx, false, null, 'high');
            }
        }

        foreach ($this->lexicon->ordinalMap() as $token => $idx) {
            if (preg_match('/\b'.preg_quote($token, '/').'\b/u', $normalizedMessage) === 1) {
                return $this->resultFromIndex($proposals, min($idx, $count - 1), false, null, 'medium');
            }
        }

        if (preg_match('/\blast\b/u', $normalizedMessage) === 1) {
            return $this->resultFromIndex($proposals, max(0, $count - 1), false, null, 'medium');
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

        if ($this->lexicon->hasAmbiguousPronoun($normalizedMessage)) {
            return ['index' => null, 'proposal_uuid' => null, 'ambiguous' => true, 'reason' => 'Please tell me which listed item to edit (first, second, last, or by title).', 'confidence' => 'low', 'candidate_titles' => $this->topCandidateTitles($proposals)];
        }

        return ['index' => null, 'proposal_uuid' => null, 'ambiguous' => true, 'reason' => 'Please specify which listed item to edit (first, second, last, or by title).', 'confidence' => 'low', 'candidate_titles' => $this->topCandidateTitles($proposals)];
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
}
