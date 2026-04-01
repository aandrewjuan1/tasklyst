<?php

namespace App\Services\LLM\Scheduling;

final class ScheduleEditTargetResolver
{
    public function __construct(private readonly ScheduleEditLexicon $lexicon) {}

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     * @return array{index: int|null, ambiguous: bool, reason: string|null}
     */
    public function resolvePrimaryTarget(string $normalizedMessage, array $proposals): array
    {
        $count = count($proposals);
        if ($count < 1) {
            return ['index' => null, 'ambiguous' => true, 'reason' => 'There are no editable schedule items yet.'];
        }

        if (preg_match('/\bitem\s*#?(\d+)\b/u', $normalizedMessage, $m) === 1) {
            $idx = (int) $m[1] - 1;
            if ($idx >= 0 && $idx < $count) {
                return ['index' => $idx, 'ambiguous' => false, 'reason' => null];
            }
        }

        foreach ($this->lexicon->ordinalMap() as $token => $idx) {
            if (preg_match('/\b'.preg_quote($token, '/').'\b/u', $normalizedMessage) === 1) {
                return ['index' => min($idx, $count - 1), 'ambiguous' => false, 'reason' => null];
            }
        }

        if (preg_match('/\blast\b/u', $normalizedMessage) === 1) {
            return ['index' => max(0, $count - 1), 'ambiguous' => false, 'reason' => null];
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
            return ['index' => $matched[0], 'ambiguous' => false, 'reason' => null];
        }
        if (count($matched) > 1) {
            return ['index' => null, 'ambiguous' => true, 'reason' => 'I found multiple matching items. Please say which one to edit.'];
        }

        if ($this->lexicon->hasAmbiguousPronoun($normalizedMessage)) {
            return ['index' => null, 'ambiguous' => true, 'reason' => 'Please tell me which listed item to edit (first, second, last, or by title).'];
        }

        return ['index' => null, 'ambiguous' => true, 'reason' => 'Please specify which listed item to edit (first, second, last, or by title).'];
    }
}
