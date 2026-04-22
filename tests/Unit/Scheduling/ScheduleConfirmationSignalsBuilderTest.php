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

it('formats nearest available window display label using daypart only', function (): void {
    $builder = new ScheduleConfirmationSignalsBuilder;
    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-04-02',
        'time_window' => ['start' => '08:00', 'end' => '22:00'],
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-04-02',
            'end_date' => '2026-04-02',
            'label' => 'explicit_date_month_day',
        ],
        'events_for_busy' => [],
        'school_class_busy_intervals' => [],
        'tasks' => [],
    ];
    $context = ['time_window_strict' => false];
    $digest = [
        'unplaced_units' => [],
        'partial_placed_count' => 0,
        'top_n_shortfall' => false,
    ];

    $out = $builder->enrich($snapshot, $context, $digest, [], []);

    expect((string) ($out['confirmation_signals']['nearest_available_window']['display_label'] ?? ''))->toContain('morning');
    expect((string) ($out['confirmation_signals']['nearest_available_window']['display_label'] ?? ''))->not->toContain(':');
});

it('treats scheduled tasks as busy when finding nearest available window', function (): void {
    $builder = new ScheduleConfirmationSignalsBuilder;
    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-04-02',
        'time_window' => ['start' => '08:00', 'end' => '12:00'],
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-04-02',
            'end_date' => '2026-04-02',
            'label' => 'explicit_date_month_day',
        ],
        'events_for_busy' => [],
        'school_class_busy_intervals' => [],
        'tasks' => [[
            'starts_at' => '2026-04-02T08:00:00+00:00',
            'ends_at' => '2026-04-02T11:00:00+00:00',
            'duration' => 180,
        ]],
    ];
    $context = ['time_window_strict' => false];
    $digest = [
        'unplaced_units' => [],
        'partial_placed_count' => 0,
        'top_n_shortfall' => false,
    ];

    $out = $builder->enrich($snapshot, $context, $digest, [], []);

    expect((string) ($out['confirmation_signals']['nearest_available_window']['start_time'] ?? ''))->toBe('11:00');
    expect((string) ($out['confirmation_signals']['nearest_available_window']['daypart'] ?? ''))->toBe('morning');
});

it('falls back to same-day afternoon when requested morning window is full', function (): void {
    $builder = new ScheduleConfirmationSignalsBuilder;
    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-04-02',
        'time_window' => ['start' => '08:00', 'end' => '12:00'],
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-04-02',
            'end_date' => '2026-04-02',
            'label' => 'explicit_date_month_day',
        ],
        'events_for_busy' => [[
            'starts_at' => '2026-04-02T08:00:00+00:00',
            'ends_at' => '2026-04-02T12:00:00+00:00',
        ]],
        'school_class_busy_intervals' => [],
        'tasks' => [],
    ];
    $context = ['time_window_strict' => false];
    $digest = [
        'unplaced_units' => [],
        'partial_placed_count' => 0,
        'top_n_shortfall' => false,
    ];

    $out = $builder->enrich($snapshot, $context, $digest, [], ['time_window_hint' => 'morning']);

    expect((string) ($out['confirmation_signals']['nearest_available_window']['date'] ?? ''))->toBe('2026-04-02');
    expect((string) ($out['confirmation_signals']['nearest_available_window']['daypart'] ?? ''))->toBe('afternoon');
});
