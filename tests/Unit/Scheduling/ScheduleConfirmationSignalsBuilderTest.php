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

it('does not emit unplaced_units trigger for implicit prioritize_schedule shortfall', function (): void {
    $builder = new ScheduleConfirmationSignalsBuilder;
    $snapshot = [
        'timezone' => 'UTC',
        'time_window' => ['start' => '18:30', 'end' => '22:00'],
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-04-23',
            'end_date' => '2026-04-23',
            'label' => 'default_today',
        ],
    ];
    $context = ['time_window_strict' => false];
    $digest = [
        'requested_count_source' => 'system_default',
        'unplaced_units' => [[
            'entity_type' => 'task',
            'entity_id' => 42,
            'title' => 'Third task',
            'reason' => 'horizon_exhausted',
        ]],
        'top_n_shortfall' => false,
        'partial_placed_count' => 0,
    ];
    $proposals = [[
        'title' => 'First task',
        'start_datetime' => '2026-04-23T18:30:00+00:00',
        'end_datetime' => '2026-04-23T19:30:00+00:00',
    ]];
    $options = [
        'schedule_source' => 'prioritize_schedule',
        'target_entities' => [[
            'entity_type' => 'task',
            'entity_id' => 42,
        ]],
        'explicit_requested_count' => 0,
    ];

    $out = $builder->enrich($snapshot, $context, $digest, $proposals, $options);

    expect($out['confirmation_signals']['triggers'] ?? [])->not->toContain('unplaced_units');
});

it('emits unplaced_units trigger for explicit prioritize_schedule shortfall', function (): void {
    $builder = new ScheduleConfirmationSignalsBuilder;
    $snapshot = [
        'timezone' => 'UTC',
        'time_window' => ['start' => '18:30', 'end' => '22:00'],
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-04-23',
            'end_date' => '2026-04-23',
            'label' => 'default_today',
        ],
    ];
    $context = ['time_window_strict' => false];
    $digest = [
        'requested_count_source' => 'explicit_user',
        'unplaced_units' => [[
            'entity_type' => 'task',
            'entity_id' => 42,
            'title' => 'Third task',
            'reason' => 'horizon_exhausted',
        ]],
        'top_n_shortfall' => true,
        'partial_placed_count' => 0,
    ];
    $proposals = [[
        'title' => 'First task',
        'start_datetime' => '2026-04-23T18:30:00+00:00',
        'end_datetime' => '2026-04-23T19:30:00+00:00',
    ]];
    $options = [
        'schedule_source' => 'prioritize_schedule',
        'target_entities' => [[
            'entity_type' => 'task',
            'entity_id' => 42,
        ]],
        'explicit_requested_count' => 3,
    ];

    $out = $builder->enrich($snapshot, $context, $digest, $proposals, $options);

    expect($out['confirmation_signals']['triggers'] ?? [])->toContain('unplaced_units');
});

it('does not emit unplaced_units trigger for implicit schedule shortfall', function (): void {
    $builder = new ScheduleConfirmationSignalsBuilder;
    $snapshot = [
        'timezone' => 'UTC',
        'time_window' => ['start' => '18:30', 'end' => '22:00'],
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-04-23',
            'end_date' => '2026-04-23',
            'label' => 'default_today',
        ],
    ];
    $context = ['time_window_strict' => false];
    $digest = [
        'requested_count_source' => 'system_default',
        'unplaced_units' => [[
            'entity_type' => 'task',
            'entity_id' => 42,
            'title' => 'Third task',
            'reason' => 'horizon_exhausted',
        ]],
        'top_n_shortfall' => false,
        'partial_placed_count' => 0,
    ];
    $proposals = [[
        'title' => 'First task',
        'start_datetime' => '2026-04-23T18:30:00+00:00',
        'end_datetime' => '2026-04-23T19:30:00+00:00',
    ]];
    $options = [
        'schedule_source' => 'schedule',
        'target_entities' => [[
            'entity_type' => 'task',
            'entity_id' => 42,
        ]],
        'explicit_requested_count' => 0,
        'is_strict_set_contract' => false,
    ];

    $out = $builder->enrich($snapshot, $context, $digest, $proposals, $options);

    expect($out['confirmation_signals']['triggers'] ?? [])->not->toContain('unplaced_units');
});

it('suppresses placement_outside_horizon trigger for implicit later rollover with full placement', function (): void {
    $builder = new ScheduleConfirmationSignalsBuilder;
    $snapshot = [
        'timezone' => 'UTC',
        'time_window' => ['start' => '22:00', 'end' => '22:00'],
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-04-24',
            'end_date' => '2026-04-24',
            'label' => 'default_today',
        ],
    ];
    $context = [
        'time_window_strict' => false,
        'schedule_intent_flags' => [
            'is_plain_later_default' => true,
        ],
    ];
    $digest = [
        'attempted_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-04-24',
            'end_date' => '2026-04-24',
            'label' => 'default_today',
        ],
        'requested_count' => 5,
        'full_placed_count' => 5,
        'top_n_shortfall' => false,
        'unplaced_units' => [],
        'partial_placed_count' => 0,
    ];
    $proposals = [[
        'title' => 'Task A',
        'start_datetime' => '2026-04-25T08:00:00+00:00',
        'end_datetime' => '2026-04-25T09:00:00+00:00',
    ]];
    $options = ['time_window_hint' => 'later'];

    $out = $builder->enrich($snapshot, $context, $digest, $proposals, $options);

    expect($out['confirmation_signals']['triggers'] ?? [])->not->toContain('placement_outside_horizon');
});

it('emits unplaced_units trigger for strict set contract without explicit numeric count', function (): void {
    $builder = new ScheduleConfirmationSignalsBuilder;
    $snapshot = [
        'timezone' => 'UTC',
        'time_window' => ['start' => '18:30', 'end' => '22:00'],
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-04-23',
            'end_date' => '2026-04-23',
            'label' => 'default_today',
        ],
    ];
    $context = ['time_window_strict' => false];
    $digest = [
        'requested_count_source' => 'system_default',
        'is_strict_set_contract' => true,
        'unplaced_units' => [[
            'entity_type' => 'task',
            'entity_id' => 42,
            'title' => 'Third task',
            'reason' => 'horizon_exhausted',
        ]],
        'top_n_shortfall' => false,
        'partial_placed_count' => 0,
    ];
    $proposals = [[
        'title' => 'First task',
        'start_datetime' => '2026-04-23T18:30:00+00:00',
        'end_datetime' => '2026-04-23T19:30:00+00:00',
    ]];
    $options = [
        'schedule_source' => 'schedule',
        'target_entities' => [[
            'entity_type' => 'task',
            'entity_id' => 42,
        ]],
        'explicit_requested_count' => 0,
        'is_strict_set_contract' => true,
    ];

    $out = $builder->enrich($snapshot, $context, $digest, $proposals, $options);

    expect($out['confirmation_signals']['triggers'] ?? [])->toContain('unplaced_units');
});
