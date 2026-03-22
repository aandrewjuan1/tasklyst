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

it('relaxes empty priority+time intersection by choosing priority-only', function (): void {
    $service = app(TaskPrioritizationService::class);

    $timezone = 'UTC';
    $now = CarbonImmutable::now($timezone);
    $today = $now->toDateString();

    $tasks = [
        [
            'id' => 1,
            'title' => 'Low due today',
            'priority' => 'low',
            'status' => 'to_do',
            'ends_at' => $now->addHours(2)->toIso8601String(),
            'duration_minutes' => 60,
        ],
        [
            'id' => 2,
            'title' => 'Urgent due tomorrow',
            'priority' => 'urgent',
            'status' => 'to_do',
            'ends_at' => $now->addDay()->addHours(2)->toIso8601String(),
            'duration_minutes' => 60,
        ],
    ];

    $context = [
        'priority_filters' => ['urgent'],
        'time_constraint' => 'today',
    ];

    $top = $service->getTopTask($tasks, $today, $context);

    expect($top)->not->toBeNull();
    expect($top['id'])->toBe(2);
});

it('relaxes empty task keyword filtering and still picks best task', function (): void {
    $service = app(TaskPrioritizationService::class);

    $timezone = 'UTC';
    $now = CarbonImmutable::now($timezone);
    $today = $now->toDateString();

    $tasks = [
        [
            'id' => 1,
            'title' => 'Physics notes',
            'priority' => 'low',
            'status' => 'to_do',
            'ends_at' => $now->addHours(2)->toIso8601String(),
            'duration_minutes' => 30,
        ],
        [
            'id' => 2,
            'title' => 'Biology homework',
            'priority' => 'high',
            'status' => 'to_do',
            'ends_at' => $now->addDay()->addHours(2)->toIso8601String(),
            'duration_minutes' => 30,
        ],
    ];

    $context = [
        'task_keywords' => ['math'],
    ];

    $top = $service->getTopTask($tasks, $today, $context);

    expect($top)->not->toBeNull();
    expect($top['id'])->toBe(1);
});

it('matches task keywords against subject_name', function (): void {
    $service = app(TaskPrioritizationService::class);

    $timezone = 'UTC';
    $now = CarbonImmutable::now($timezone);
    $today = $now->toDateString();

    $tasks = [
        [
            'id' => 1,
            'title' => 'Draft 1',
            'subject_name' => 'ENG 105 – Academic Writing',
            'tags' => [],
            'priority' => 'low',
            'status' => 'to_do',
            'ends_at' => $now->addHours(2)->toIso8601String(),
            'duration_minutes' => 30,
        ],
        [
            'id' => 2,
            'title' => 'Physics notes',
            'subject_name' => 'Physics',
            'tags' => [],
            'priority' => 'urgent',
            'status' => 'to_do',
            'ends_at' => $now->addHours(3)->toIso8601String(),
            'duration_minutes' => 30,
        ],
    ];

    $context = [
        'task_keywords' => ['writing'],
    ];

    $top = $service->getTopTask($tasks, $today, $context);

    // Keyword filtering should keep only the writing-related task (id=1).
    expect($top)->not->toBeNull();
    expect($top['id'])->toBe(1);
});

it('matches task keywords against tag names', function (): void {
    $service = app(TaskPrioritizationService::class);

    $timezone = 'UTC';
    $now = CarbonImmutable::now($timezone);
    $today = $now->toDateString();

    $tasks = [
        [
            'id' => 1,
            'title' => 'Kitchen cleanup',
            'subject_name' => null,
            'tags' => ['Household'],
            'priority' => 'low',
            'status' => 'to_do',
            'ends_at' => $now->addHours(2)->toIso8601String(),
            'duration_minutes' => 30,
        ],
        [
            'id' => 2,
            'title' => 'Evening walk',
            'subject_name' => null,
            'tags' => ['Health'],
            'priority' => 'low',
            'status' => 'to_do',
            'ends_at' => $now->addHours(1)->toIso8601String(),
            'duration_minutes' => 30,
        ],
    ];

    $context = [
        'task_keywords' => ['household'],
    ];

    $top = $service->getTopTask($tasks, $today, $context);

    expect($top)->not->toBeNull();
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

it('prefers urgent sooner deadlines over short-duration future tasks', function (): void {
    $service = app(TaskPrioritizationService::class);

    $timezone = 'UTC';
    $now = CarbonImmutable::now($timezone);
    $today = $now->toDateString();

    $snapshot = [
        'today' => $today,
        'timezone' => $timezone,
        'tasks' => [
            [
                'id' => 1,
                'title' => 'Urgent soon',
                'priority' => 'urgent',
                'status' => 'to_do',
                'ends_at' => $now->addDays(2)->setTime(23, 59)->toIso8601String(),
                'duration_minutes' => 240,
            ],
            [
                'id' => 2,
                'title' => 'Short future',
                'priority' => 'medium',
                'status' => 'to_do',
                'ends_at' => $now->addDays(9)->setTime(23, 59)->toIso8601String(),
                'duration_minutes' => 15,
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

it('prefers near events over medium tasks due today', function (): void {
    $service = app(TaskPrioritizationService::class);

    $timezone = 'UTC';
    $now = CarbonImmutable::now($timezone);

    $snapshot = [
        'today' => $now->toDateString(),
        'timezone' => $timezone,
        'tasks' => [
            [
                'id' => 1,
                'title' => 'Medium task due today',
                'priority' => 'medium',
                'status' => 'to_do',
                'ends_at' => $now->addHours(3)->toIso8601String(),
                'duration_minutes' => 60,
            ],
        ],
        'events' => [
            [
                'id' => 10,
                'title' => 'Meeting soon',
                'starts_at' => $now->addMinutes(30)->toIso8601String(),
                'ends_at' => $now->addMinutes(60)->toIso8601String(),
                'all_day' => false,
                'status' => 'scheduled',
            ],
        ],
        'projects' => [],
    ];

    $top = $service->getTopFocus($snapshot);

    expect($top)->not->toBeNull();
    expect($top['type'])->toBe('event');
    expect($top['id'])->toBe(10);
});

it('school browse domain keeps academic tasks and drops errands like school bag titles', function (): void {
    $service = app(TaskPrioritizationService::class);

    $timezone = 'UTC';
    $now = CarbonImmutable::now($timezone);

    $tasks = [
        [
            'id' => 1,
            'title' => 'Prepare tomorrow’s school bag',
            'subject_name' => null,
            'teacher_name' => null,
            'tags' => [],
            'priority' => 'medium',
            'status' => 'to_do',
            'ends_at' => $now->addDay()->toIso8601String(),
            'duration_minutes' => 30,
            'is_recurring' => true,
        ],
        [
            'id' => 2,
            'title' => 'Problem set chapter 4',
            'subject_name' => 'Mathematics',
            'teacher_name' => null,
            'tags' => [],
            'priority' => 'high',
            'status' => 'to_do',
            'ends_at' => $now->addDay()->toIso8601String(),
            'duration_minutes' => 45,
            'is_recurring' => false,
        ],
    ];

    $ranked = $service->prioritizeTasks($tasks, $now, [
        'browse_domain' => 'school',
        'time_constraint' => 'this_week',
    ]);

    expect($ranked)->toHaveCount(1);
    expect($ranked[0]['id'])->toBe(2);
});
