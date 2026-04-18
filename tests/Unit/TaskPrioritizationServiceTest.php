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

it('candidate provider includes overdue tasks', function (): void {
    $user = \App\Models\User::factory()->create();

    $now = now();

    \App\Models\Task::factory()->for($user)->create([
        'title' => 'Overdue outside day window',
        'status' => \App\Enums\TaskStatus::ToDo,
        'priority' => \App\Enums\TaskPriority::Urgent,
        'start_datetime' => $now->copy()->subDays(2),
        'end_datetime' => $now->copy()->subDay(),
        'completed_at' => null,
    ]);

    $candidates = app(\App\Services\LLM\Prioritization\AssistantCandidateProvider::class)->candidatesForUser($user, 200);
    $titles = collect($candidates['tasks'] ?? [])->pluck('title')->all();

    expect($titles)->toContain('Overdue outside day window');
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

it('task preference still allows time-critical events, and falls back when no tasks exist', function (): void {
    $service = app(TaskPrioritizationService::class);

    $timezone = 'UTC';
    $now = CarbonImmutable::now($timezone);

    $snapshot = [
        'today' => $now->toDateString(),
        'timezone' => $timezone,
        'tasks' => [
            [
                'id' => 1,
                'title' => 'Task due tomorrow',
                'priority' => 'medium',
                'status' => 'to_do',
                'ends_at' => $now->addDay()->toIso8601String(),
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

    $ranked = $service->prioritizeFocus($snapshot, ['entity_type_preference' => 'task']);
    expect($ranked)->not->toBeEmpty();
    // Meeting soon is time-critical and should be included even with task preference.
    expect(collect($ranked)->pluck('type')->all())->toContain('event');

    $rankedNoTasks = $service->prioritizeFocus([
        'today' => $now->toDateString(),
        'timezone' => $timezone,
        'tasks' => [],
        'events' => $snapshot['events'],
        'projects' => [],
    ], ['entity_type_preference' => 'task']);
    expect($rankedNoTasks)->not->toBeEmpty();
    expect($rankedNoTasks[0]['type'])->toBe('event');
});

it('school domain keeps academic tasks and drops errands like school bag titles', function (): void {
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
        'domain_focus' => 'school',
        'time_constraint' => 'this_week',
    ]);

    expect($ranked)->toHaveCount(1);
    expect($ranked[0]['id'])->toBe(2);
});

it('defaults to non-recurring tasks when both recurring and normal tasks exist', function (): void {
    $service = app(TaskPrioritizationService::class);

    $timezone = 'UTC';
    $now = CarbonImmutable::now($timezone);

    $snapshot = [
        'today' => $now->toDateString(),
        'timezone' => $timezone,
        'tasks' => [
            [
                'id' => 1,
                'title' => 'Walk 10k steps',
                'priority' => 'urgent',
                'status' => 'to_do',
                'ends_at' => $now->addHours(2)->toIso8601String(),
                'duration_minutes' => 45,
                'is_recurring' => true,
            ],
            [
                'id' => 2,
                'title' => 'Submit assignment draft',
                'priority' => 'medium',
                'status' => 'to_do',
                'ends_at' => $now->addHours(3)->toIso8601String(),
                'duration_minutes' => 60,
                'is_recurring' => false,
            ],
        ],
        'events' => [],
        'projects' => [],
    ];

    $ranked = $service->prioritizeFocus($snapshot);

    expect($ranked)->not->toBeEmpty();
    expect(collect($ranked)->pluck('id')->all())->toContain(2);
    expect(collect($ranked)->pluck('id')->all())->not->toContain(1);
});

it('keeps recurring tasks when they are the only available tasks', function (): void {
    $service = app(TaskPrioritizationService::class);

    $timezone = 'UTC';
    $now = CarbonImmutable::now($timezone);

    $snapshot = [
        'today' => $now->toDateString(),
        'timezone' => $timezone,
        'tasks' => [
            [
                'id' => 9,
                'title' => 'Daily stretching',
                'priority' => 'medium',
                'status' => 'to_do',
                'ends_at' => $now->addHours(8)->toIso8601String(),
                'duration_minutes' => 20,
                'is_recurring' => true,
            ],
        ],
        'events' => [],
        'projects' => [],
    ];

    $ranked = $service->prioritizeFocus($snapshot);

    expect($ranked)->not->toBeEmpty();
    expect($ranked[0]['type'])->toBe('task');
    expect($ranked[0]['id'])->toBe(9);
});
