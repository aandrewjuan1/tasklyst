<?php

namespace App\Services\LLM\Scheduling;

final class ScheduleEditLexicon
{
    /**
     * Alternation fragment (no outer parentheses) for phrases that refer to a row in the
     * numbered schedule draft. Used by reorder regex captures; keep in sync with
     * {@see ScheduleEditTargetResolver} ranking / item-index detection.
     */
    public function scheduleDraftRowPositionInnerAlternation(): string
    {
        return implode('|', [
            'first',
            '1st',
            'second',
            '2nd',
            'third',
            '3rd',
            'last',
            'item\s*#?\d+',
            'top\s*#?\s*\d+',
            'top\s+(?:one|first|two|second|three|third)',
            'rank(?:ed)?\s*#?\s*\d+',
            '(?:line|row|slot)\s*#?\s*\d+',
            '(?:number|no\.|nr\.)\s*\d+',
            '#[1-9]\d{0,2}',
        ]);
    }

    /**
     * Full capture for "move (TARGET) to …" style reorder parsing.
     */
    public function scheduleDraftReorderTargetPattern(): string
    {
        return '(?:the\s+)?(?:'.$this->scheduleDraftRowPositionInnerAlternation().')(?:\s+one)?';
    }

    /**
     * @return array<string, int>
     */
    public function ordinalMap(): array
    {
        return [
            'first' => 0,
            '1st' => 0,
            'second' => 1,
            '2nd' => 1,
            'third' => 2,
            '3rd' => 2,
        ];
    }

    public function hasAmbiguousPronoun(string $message): bool
    {
        return preg_match('/\b(it|this|that|this one|that one|same one)\b/u', $message) === 1;
    }

    public function looksLikeReorder(string $message): bool
    {
        return preg_match('/\b(before|after|first|last|reorder|swap|drag|slide)\b/u', $message) === 1;
    }
}
