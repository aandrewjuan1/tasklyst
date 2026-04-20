<?php

use App\Services\LLM\Scheduling\ScheduleConfirmationSignalsBuilder;

it('flags requested_window_unsatisfied when placement local time is outside the snapshot time window', function (): void {
    $builder = new ScheduleConfirmationSignalsBuilder;
    $snapshot = [
        'timezone' => 'UTC',
        'time_window' => ['start' => '15:00', 'end' => '18:00'],
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-04-02',
            'end_date' => '2026-04-02',
            'label' => 'default_today',
        ],
    ];
    $context = ['time_window_strict' => false];
    $digest = [
        'unplaced_units' => [],
        'partial_placed_count' => 0,
        'top_n_shortfall' => false,
    ];
    $proposals = [[
        'title' => 'Task A',
        'start_datetime' => '2026-04-02T10:00:00+00:00',
        'end_datetime' => '2026-04-02T11:00:00+00:00',
    ]];

    $out = $builder->enrich($snapshot, $context, $digest, $proposals, ['time_window_hint' => 'later_afternoon']);

    expect($out['confirmation_signals']['triggers'] ?? [])->toContain('requested_window_unsatisfied');
    expect($out['confirmation_signals']['triggers'] ?? [])->toContain('hinted_window_unsatisfied');
});

it('includes adaptive_relaxed_placement when digest fallback mode is set', function (): void {
    $builder = new ScheduleConfirmationSignalsBuilder;
    $snapshot = [
        'timezone' => 'UTC',
        'time_window' => ['start' => '08:00', 'end' => '22:00'],
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-04-02',
            'end_date' => '2026-04-02',
            'label' => 'default_today',
        ],
    ];
    $context = ['time_window_strict' => false];
    $digest = [
        'fallback_mode' => 'auto_relaxed_today_or_tomorrow',
        'unplaced_units' => [],
        'partial_placed_count' => 0,
        'top_n_shortfall' => false,
    ];
    $proposals = [[
        'title' => 'Task A',
        'start_datetime' => '2026-04-02T16:00:00+00:00',
        'end_datetime' => '2026-04-02T17:00:00+00:00',
    ]];

    $out = $builder->enrich($snapshot, $context, $digest, $proposals, []);

    expect($out['confirmation_signals']['triggers'] ?? [])->toContain('adaptive_relaxed_placement');
});
