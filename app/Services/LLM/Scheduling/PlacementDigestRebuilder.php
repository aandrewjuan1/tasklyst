<?php

namespace App\Services\LLM\Scheduling;

use Carbon\CarbonImmutable;

final class PlacementDigestRebuilder
{
    /**
     * @param  list<array<string, mixed>>  $proposals
     * @param  array<string, mixed>|null  $existingDigest
     * @return array<string, mixed>
     */
    public function rebuildFromProposals(array $proposals, ?array $existingDigest): array
    {
        $skippedTargets = is_array($existingDigest['skipped_targets'] ?? null)
            ? $existingDigest['skipped_targets']
            : [];

        $unplacedUnits = is_array($existingDigest['unplaced_units'] ?? null)
            ? $existingDigest['unplaced_units']
            : [];

        $partialUnits = is_array($existingDigest['partial_units'] ?? null)
            ? $existingDigest['partial_units']
            : [];

        // Keep only real placements for count logic (avoid placeholder rows).
        $realProposals = array_values(array_filter(
            $proposals,
            static fn (mixed $p): bool => is_array($p) && trim((string) ($p['title'] ?? '')) !== 'No schedulable items found'
        ));

        $seenDates = [];
        $placementDates = [];
        foreach ($realProposals as $p) {
            if (! is_array($p)) {
                continue;
            }

            $start = (string) ($p['start_datetime'] ?? '');
            if (trim($start) === '') {
                continue;
            }

            try {
                $d = CarbonImmutable::parse($start)->format('Y-m-d');
            } catch (\Throwable) {
                continue;
            }

            if (! array_key_exists($d, $seenDates)) {
                $seenDates[$d] = true;
                $placementDates[] = $d;
            }
        }

        $daysUsed = $placementDates;

        $summary = sprintf(
            'placed_proposals=%d days_used=%d unplaced_units=%d',
            count($realProposals),
            count($daysUsed),
            count($unplacedUnits)
        );

        return [
            'placement_dates' => $placementDates,
            'days_used' => $daysUsed,
            'skipped_targets' => array_values($skippedTargets),
            'unplaced_units' => array_values($unplacedUnits),
            'partial_units' => array_values($partialUnits),
            'summary' => $summary,
        ];
    }
}
