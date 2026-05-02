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

it('adds afternoon context when the hinted window targets afternoon hours', function (): void {
    $explainer = new ScheduleFallbackReasonExplainer;

    $reasons = $explainer->summarize([
        'requested_window' => [
            'start' => '13:00',
            'end' => '17:59',
        ],
        'placement_digest' => [
            'confirmation_signals' => [
                'triggers' => ['unplaced_units', 'strict_window_no_fit'],
            ],
            'unplaced_units' => [
                ['title' => 'Deep work block', 'reason' => 'window_conflict', 'minutes' => 40],
            ],
        ],
        'proposals' => [],
    ], 'afternoon');

    expect(implode(' ', $reasons))->toContain('Your afternoon window left limited uninterrupted time');
});
