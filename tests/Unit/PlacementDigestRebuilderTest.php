<?php

use App\Services\LLM\Scheduling\PlacementDigestRebuilder;

test('rebuildFromProposals replaces placement_dates and days_used after refinement', function (): void {
    $builder = new PlacementDigestRebuilder;

    $existingDigest = [
        'placement_dates' => ['2026-04-02'],
        'days_used' => ['2026-04-02'],
        'skipped_targets' => [],
        'unplaced_units' => [],
        'partial_units' => [],
        'summary' => 'placed_proposals=1 days_used=1 unplaced_units=0',
    ];

    $proposals = [
        [
            'title' => 'Impossible 5h study block before quiz',
            'start_datetime' => '2026-04-03T08:00:00+08:00',
            'end_datetime' => '2026-04-03T12:00:00+08:00',
        ],
    ];

    $rebuilt = $builder->rebuildFromProposals($proposals, $existingDigest);

    expect($rebuilt['placement_dates'])->toBe(['2026-04-03']);
    expect($rebuilt['days_used'])->toBe(['2026-04-03']);
    expect($rebuilt['summary'])->toBe('placed_proposals=1 days_used=1 unplaced_units=0');
});
