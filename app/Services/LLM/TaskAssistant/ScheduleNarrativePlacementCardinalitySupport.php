<?php

namespace App\Services\LLM\TaskAssistant;

/**
 * Detects schedule narrative copy that claims more tasks were time-placed than the structured rows/digest allow.
 */
final class ScheduleNarrativePlacementCardinalitySupport
{
    /**
     * @param  array<string, mixed>|null  $placementDigest
     */
    public static function unplacedUnitCount(?array $placementDigest): int
    {
        if (! is_array($placementDigest)) {
            return 0;
        }
        $u = $placementDigest['unplaced_units'] ?? null;

        return is_array($u) ? count($u) : 0;
    }

    /**
     * Conservative detection: when some candidates did not get slots, block phrases that imply every
     * multi-item request was fully scheduled.
     */
    public static function misrepresentsPlacementCount(
        string $text,
        int $placedBlockCount,
        int $unplacedCount,
    ): bool {
        $text = trim($text);
        if ($text === '' || $placedBlockCount < 1) {
            return false;
        }

        $t = mb_strtolower($text);

        if ($unplacedCount >= 1) {
            if ($placedBlockCount <= 2 && preg_match('/\ball\s+three\b/u', $t) === 1) {
                return true;
            }
            if ($placedBlockCount <= 2 && preg_match('/\bplacing\s+all\s+three\b/u', $t) === 1) {
                return true;
            }
            if ($placedBlockCount <= 1 && preg_match('/\bboth\s+tasks\b/u', $t) === 1) {
                return true;
            }
            if ($placedBlockCount <= 1 && preg_match('/\b(i\s*[\x{2019}\']\s*ve\s+)?scheduled\s+your\s+top\s+tasks\b/iu', $t) === 1) {
                return true;
            }
            if ($placedBlockCount <= 2 && preg_match('/\ball\s+three\s+of\s+these\b/u', $t) === 1) {
                return true;
            }
        }

        if ($placedBlockCount < 3
            && $unplacedCount >= 1
            && preg_match('/\ball\s+three\b/u', $t) === 1
            && preg_match('/\b(together|scheduled|placed|followed\s+by|blocks?\s+in)\b/u', $t) === 1) {
            return true;
        }

        if ($unplacedCount === 0
            && $placedBlockCount > 0
            && $placedBlockCount < 3
            && preg_match('/\ball\s+three\b/u', $t) === 1
            && preg_match('/\b(placing|placed|scheduled|together)\b/u', $t) === 1) {
            return true;
        }

        return false;
    }

    /**
     * True when copy implies multiple tasks received concrete time blocks but only one block exists.
     */
    public static function claimsMultiplePlacedTasksWithSingleBlock(string $text, int $placedBlockCount): bool
    {
        if ($placedBlockCount !== 1) {
            return false;
        }

        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return false;
        }

        if (preg_match('/\b(these|those|the)\s+two\s+tasks\b/u', $t) === 1) {
            return true;
        }

        if (preg_match('/\bfitting\s+these\s+two\b/u', $t) === 1) {
            return true;
        }

        if (preg_match('/\bboth\s+tasks\b/u', $t) === 1) {
            return true;
        }

        if (preg_match('/\binto\s+a\s+couple\s+(of\s+)?days\b/u', $t) === 1) {
            return true;
        }

        return false;
    }
}
