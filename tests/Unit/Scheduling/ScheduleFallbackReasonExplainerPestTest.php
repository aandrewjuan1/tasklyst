<?php

use App\Services\LLM\Scheduling\ScheduleFallbackReasonExplainer;

it('uses candidate-generation reason for empty placement when no candidate units were built', function (): void {
    $explainer = new ScheduleFallbackReasonExplainer;

    $reasons = $explainer->summarize([
        'requested_window' => [
            'start' => '10:20',
            'end' => '20:25',
        ],
        'placement_digest' => [
            'candidate_units_count' => 0,
            'confirmation_signals' => [
                'triggers' => ['empty_placement'],
            ],
            'unplaced_units' => [],
        ],
        'proposals' => [],
    ]);

    expect($reasons)->toContain('I could not build a schedulable work block for this task with the current setup yet.');
});
