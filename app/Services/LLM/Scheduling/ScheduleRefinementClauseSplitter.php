<?php

namespace App\Services\LLM\Scheduling;

/**
 * Splits a normalized schedule-refinement message into ordered clauses for multi-edit parsing.
 */
final class ScheduleRefinementClauseSplitter
{
    private const EDIT_VERB = '(?:move|put|shift|set|reschedule|change|edit|adjust|swap|make)\b';

    private const EDIT_VERB_PREFIX = '^'.self::EDIT_VERB;

    /**
     * @return list<string>
     */
    public function split(string $normalizedMessage): array
    {
        $trimmed = trim($normalizedMessage);
        if ($trimmed === '') {
            return [];
        }

        $parts = $this->splitRecursive($trimmed);
        $out = [];
        foreach ($parts as $part) {
            $p = trim($part);
            if ($p !== '') {
                $out[] = $p;
            }
        }

        return $out !== [] ? $out : [$trimmed];
    }

    /**
     * @return list<string>
     */
    private function splitRecursive(string $text): array
    {
        $split = $this->findFirstDelimiterSplit($text);
        if ($split === null) {
            return [$text];
        }

        [$left, $right] = $split;
        $left = trim($left);
        $right = trim($right);
        if ($left === '') {
            return $this->splitRecursive($right);
        }
        if ($right === '') {
            return [$left];
        }

        return array_merge($this->splitRecursive($left), $this->splitRecursive($right));
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function findFirstDelimiterSplit(string $text): ?array
    {
        $candidates = [];
        $patterns = [
            '/\s+and\s+then\s+/iu',
            '/\s+then\s+/iu',
            '/\s+after\s+that\s+/iu',
            '/\s+next\s+(?='.self::EDIT_VERB.')/iu',
            '/\s*;\s*/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE) === 1) {
                $pos = $m[0][1];
                $len = strlen($m[0][0]);
                $candidates[] = [$pos, $len];
            }
        }

        $commaOffset = 0;
        $textLen = strlen($text);
        while ($commaOffset < $textLen && preg_match('/,\s+/u', $text, $m, PREG_OFFSET_CAPTURE, $commaOffset) === 1) {
            $pos = $m[0][1];
            $len = strlen($m[0][0]);
            $afterTrim = ltrim(substr($text, $pos + $len));
            if ($afterTrim !== '' && preg_match('/'.self::EDIT_VERB_PREFIX.'/iu', $afterTrim) === 1) {
                $candidates[] = [$pos, $len];
            }
            $commaOffset = $pos + $len;
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        [$pos, $len] = $candidates[0];

        $left = substr($text, 0, $pos);
        $right = substr($text, $pos + $len);

        return [trim($left), trim($right)];
    }
}
