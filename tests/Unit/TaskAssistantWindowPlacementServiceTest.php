<?php

use App\Services\LLM\Scheduling\TaskAssistantWindowPlacementService;

it('prefers morning slot for high complexity task when morning energy bias is set', function (): void {
    $service = app(TaskAssistantWindowPlacementService::class);
    $timezone = new DateTimeZone('UTC');

    $windows = [
        [
            'start' => new DateTimeImmutable('2026-04-12 09:00:00', $timezone),
            'end' => new DateTimeImmutable('2026-04-12 11:30:00', $timezone),
        ],
        [
            'start' => new DateTimeImmutable('2026-04-12 19:00:00', $timezone),
            'end' => new DateTimeImmutable('2026-04-12 21:30:00', $timezone),
        ],
    ];

    $unit = [
        'entity_type' => 'task',
        'entity_id' => 91,
        'complexity' => 'high',
    ];
    $snapshot = [
        'schedule_preferences' => [
            'energy_bias' => 'morning',
        ],
        'tasks' => [
            ['id' => 91, 'ends_at' => null],
        ],
        'school_class_busy_intervals' => [],
    ];

    $selected = $service->selectBestFittingWindow($windows, 60, $unit, $snapshot);

    expect($selected)->not->toBeNull();
    expect($selected[1]->format('H:i'))->toBe('09:00');
});

it('prefers afternoon slot when afternoon energy bias is set', function (): void {
    $service = app(TaskAssistantWindowPlacementService::class);
    $timezone = new DateTimeZone('UTC');

    $windows = [
        [
            'start' => new DateTimeImmutable('2026-04-12 09:00:00', $timezone),
            'end' => new DateTimeImmutable('2026-04-12 11:30:00', $timezone),
        ],
        [
            'start' => new DateTimeImmutable('2026-04-12 14:00:00', $timezone),
            'end' => new DateTimeImmutable('2026-04-12 16:30:00', $timezone),
        ],
    ];

    $unit = [
        'entity_type' => 'task',
        'entity_id' => 92,
        'complexity' => 'medium',
    ];
    $snapshot = [
        'schedule_preferences' => [
            'energy_bias' => 'afternoon',
        ],
        'tasks' => [
            ['id' => 92, 'ends_at' => null],
        ],
        'school_class_busy_intervals' => [],
    ];

    $selected = $service->selectBestFittingWindow($windows, 60, $unit, $snapshot);

    expect($selected)->not->toBeNull();
    expect($selected[1]->format('H:i'))->toBe('14:00');
});

it('rewards near deadline placement for due-soon tasks', function (): void {
    $service = app(TaskAssistantWindowPlacementService::class);
    $timezone = new DateTimeZone('UTC');
    config([
        'task-assistant.schedule.window_scoring.weights.earlier_start_bonus' => 0.0,
        'task-assistant.schedule.window_scoring.weights.due_soon_multiplier' => 2.0,
    ]);

    $windows = [
        [
            'start' => new DateTimeImmutable('2026-04-12 08:00:00', $timezone),
            'end' => new DateTimeImmutable('2026-04-12 10:00:00', $timezone),
        ],
        [
            'start' => new DateTimeImmutable('2026-04-12 14:00:00', $timezone),
            'end' => new DateTimeImmutable('2026-04-12 16:00:00', $timezone),
        ],
    ];

    $unit = [
        'entity_type' => 'task',
        'entity_id' => 99,
        'complexity' => 'medium',
    ];
    $snapshot = [
        'schedule_preferences' => [
            'energy_bias' => 'balanced',
        ],
        'tasks' => [
            ['id' => 99, 'ends_at' => '2026-04-12T16:30:00+00:00'],
        ],
        'school_class_busy_intervals' => [],
    ];

    $selected = $service->selectBestFittingWindow($windows, 60, $unit, $snapshot);

    expect($selected)->not->toBeNull();
    expect($selected[1]->format('H:i'))->toBe('14:00');
});

it('prefers same-day later slot over tomorrow morning in default asap mode', function (): void {
    $service = app(TaskAssistantWindowPlacementService::class);
    $timezone = new DateTimeZone('UTC');

    $windows = [
        [
            'start' => new DateTimeImmutable('2026-04-12 17:00:00', $timezone),
            'end' => new DateTimeImmutable('2026-04-12 19:00:00', $timezone),
        ],
        [
            'start' => new DateTimeImmutable('2026-04-13 08:00:00', $timezone),
            'end' => new DateTimeImmutable('2026-04-13 10:00:00', $timezone),
        ],
    ];

    $unit = [
        'entity_type' => 'task',
        'entity_id' => 100,
        'complexity' => 'high',
    ];
    $snapshot = [
        'schedule_preferences' => [
            'energy_bias' => 'morning',
        ],
        'tasks' => [
            ['id' => 100, 'ends_at' => null],
        ],
        'school_class_busy_intervals' => [],
    ];

    $selected = $service->selectBestFittingWindow(
        $windows,
        60,
        $unit,
        $snapshot,
        new DateTimeImmutable('2026-04-12 16:30:00', $timezone),
        true
    );

    expect($selected)->not->toBeNull();
    expect($selected[1]->format('Y-m-d H:i'))->toBe('2026-04-12 17:00');
});
