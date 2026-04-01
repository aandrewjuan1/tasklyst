<?php

namespace App\Services\LLM\Scheduling;

final class ScheduleEditLexicon
{
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
        return preg_match('/\b(it|this|that|this one|that one)\b/u', $message) === 1;
    }

    public function looksLikeReorder(string $message): bool
    {
        return preg_match('/\b(before|after|first|last|reorder|swap)\b/u', $message) === 1;
    }
}
