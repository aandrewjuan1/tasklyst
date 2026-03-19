<?php

use App\Services\LLM\Prioritization\TaskPrioritizationService;

it('prioritizes overdue tasks over upcoming events and projects', function (): void {
    $service = app(TaskPrioritizationService::class);

    $snapshot = [
        'today' => '2026-03-19',
        'timezone' => 'UTC',
        'tasks' => [
            [
                'id' => 1,
                'title' => 'Overdue task',
                'priority' => 'medium',
                'ends_at' => '2026-03-18T12:00:00+00:00',
                'duration_minutes' => 30,
            ],
        ],
        'events' => [
            [
                'id' => 10,
                'title' => 'Event later today',
                'starts_at' => '2026-03-19T18:00:00+00:00',
                'ends_at' => '2026-03-19T19:00:00+00:00',
                'all_day' => false,
            ],
        ],
        'projects' => [
            [
                'id' => 20,
                'name' => 'Project ends soon',
                'start_at' => '2026-03-01T00:00:00+00:00',
                'end_at' => '2026-03-22T00:00:00+00:00',
            ],
        ],
    ];

    $top = $service->getTopFocus($snapshot);

    expect($top)->not->toBeNull();
    expect($top['type'])->toBe('task');
    expect($top['id'])->toBe(1);
});

it('prioritizes an event starting soon when there are no urgent tasks', function (): void {
    $service = app(TaskPrioritizationService::class);

    $snapshot = [
        'today' => '2026-03-19',
        'timezone' => 'UTC',
        'tasks' => [],
        'events' => [
            [
                'id' => 10,
                'title' => 'Event in 30 minutes',
                'starts_at' => '2026-03-19T00:30:00+00:00',
                'ends_at' => '2026-03-19T01:00:00+00:00',
                'all_day' => false,
            ],
        ],
        'projects' => [],
    ];

    $top = $service->getTopFocus($snapshot);

    expect($top)->not->toBeNull();
    expect($top['type'])->toBe('event');
    expect($top['id'])->toBe(10);
});

it('prioritizes an overdue project when there are no tasks or events', function (): void {
    $service = app(TaskPrioritizationService::class);

    $snapshot = [
        'today' => '2026-03-19',
        'timezone' => 'UTC',
        'tasks' => [],
        'events' => [],
        'projects' => [
            [
                'id' => 20,
                'name' => 'Overdue project',
                'start_at' => '2026-02-01T00:00:00+00:00',
                'end_at' => '2026-03-10T00:00:00+00:00',
            ],
        ],
    ];

    $top = $service->getTopFocus($snapshot);

    expect($top)->not->toBeNull();
    expect($top['type'])->toBe('project');
    expect($top['id'])->toBe(20);
});
