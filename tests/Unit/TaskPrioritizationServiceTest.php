<?php

use App\Services\LLM\Prioritization\TaskPrioritizationService;
use Carbon\CarbonImmutable;

it('prioritizes overdue tasks over upcoming events and projects', function (): void {
    $service = app(TaskPrioritizationService::class);

    $timezone = 'UTC';
    $now = CarbonImmutable::now($timezone);

    $snapshot = [
        'today' => $now->toDateString(),
        'timezone' => $timezone,
        'tasks' => [
            [
                'id' => 1,
                'title' => 'Overdue task',
                'priority' => 'medium',
                'status' => 'to_do',
                'ends_at' => $now->subDay()->subHours(2)->toIso8601String(),
                'duration_minutes' => 30,
            ],
        ],
        'events' => [
            [
                'id' => 10,
                'title' => 'Event later today',
                'starts_at' => $now->addHours(6)->toIso8601String(),
                'ends_at' => $now->addHours(7)->toIso8601String(),
                'all_day' => false,
                'status' => 'scheduled',
            ],
        ],
        'projects' => [
            [
                'id' => 20,
                'name' => 'Project ends soon',
                'start_at' => $now->subDays(20)->toIso8601String(),
                'end_at' => $now->addDays(3)->toIso8601String(),
            ],
        ],
    ];

    $top = $service->getTopFocus($snapshot);

    expect($top)->not->toBeNull();
    expect($top['type'])->toBe('task');
    expect($top['id'])->toBe(1);
});

it('prioritizes the earliest upcoming event using real now', function (): void {
    $service = app(TaskPrioritizationService::class);

    $timezone = 'UTC';
    $now = CarbonImmutable::now($timezone);

    $snapshot = [
        'today' => $now->toDateString(),
        'timezone' => $timezone,
        'tasks' => [],
        'events' => [
            [
                'id' => 10,
                'title' => 'Event in 30 minutes',
                'starts_at' => $now->addMinutes(30)->toIso8601String(),
                'ends_at' => $now->addMinutes(60)->toIso8601String(),
                'all_day' => false,
                'status' => 'scheduled',
            ],
            [
                'id' => 11,
                'title' => 'Event in 6 hours',
                'starts_at' => $now->addHours(6)->toIso8601String(),
                'ends_at' => $now->addHours(7)->toIso8601String(),
                'all_day' => false,
                'status' => 'scheduled',
            ],
        ],
        'projects' => [],
    ];

    $top = $service->getTopFocus($snapshot);

    expect($top)->not->toBeNull();
    expect($top['type'])->toBe('event');
    expect($top['id'])->toBe(10); // now + 30m should beat now + 6h
});

it('does not empty results when context filters exclude all tasks', function (): void {
    $service = app(TaskPrioritizationService::class);

    $timezone = 'UTC';
    $now = CarbonImmutable::now($timezone);

    $snapshot = [
        'today' => $now->toDateString(),
        'timezone' => $timezone,
        'tasks' => [],
        'events' => [],
        'projects' => [],
    ];

    // All tasks are "low", but context asks for "urgent" only.
    // With soft filtering, we should still pick a task rather than returning empty.
    $snapshot['tasks'] = [
        [
            'id' => 1,
            'title' => 'Low priority task A',
            'priority' => 'low',
            'status' => 'to_do',
            'ends_at' => $now->addHours(2)->toIso8601String(),
            'duration_minutes' => 30,
        ],
        [
            'id' => 2,
            'title' => 'Low priority task B',
            'priority' => 'low',
            'status' => 'to_do',
            'ends_at' => $now->addHours(6)->toIso8601String(),
            'duration_minutes' => 30,
        ],
    ];

    $context = [
        'priority_filters' => ['urgent'],
    ];

    $top = $service->getTopFocus($snapshot, $context);

    expect($top)->not->toBeNull();
    expect($top['type'])->toBe('task');
    expect($top['id'])->toBe(1);
});

it('keeps urgency dominant over small doing momentum boost', function (): void {
    $service = app(TaskPrioritizationService::class);

    $timezone = 'UTC';
    $now = CarbonImmutable::now($timezone);

    $snapshot = [
        'today' => $now->toDateString(),
        'timezone' => $timezone,
        'tasks' => [
            // Due today, not doing.
            [
                'id' => 1,
                'title' => 'Due today (to_do)',
                'priority' => 'medium',
                'status' => 'to_do',
                'ends_at' => $now->addHours(2)->toIso8601String(),
                'duration_minutes' => 60,
            ],
            // Due tomorrow, doing (should not outrank due-today).
            [
                'id' => 2,
                'title' => 'Due tomorrow (doing)',
                'priority' => 'medium',
                'status' => 'doing',
                'ends_at' => $now->addDay()->addHours(1)->toIso8601String(),
                'duration_minutes' => 60,
            ],
        ],
        'events' => [],
        'projects' => [],
    ];

    $top = $service->getTopFocus($snapshot);

    expect($top)->not->toBeNull();
    expect($top['type'])->toBe('task');
    expect($top['id'])->toBe(1);
});
