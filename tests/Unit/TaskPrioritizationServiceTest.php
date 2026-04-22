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

    $top = $service->getTopTask($tasks, $context);

    expect($top)->not->toBeNull();
    expect($top['id'])->toBe(2);
});

it('relaxes empty task keyword filtering and still picks best task', function (): void {
    $service = app(TaskPrioritizationService::class);

    $timezone = 'UTC';
    $now = CarbonImmutable::now($timezone);

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

    $top = $service->getTopTask($tasks, $context);

    expect($top)->not->toBeNull();
    expect($top['id'])->toBe(1);
});

it('matches task keywords against subject_name', function (): void {
    $service = app(TaskPrioritizationService::class);

    $timezone = 'UTC';
    $now = CarbonImmutable::now($timezone);

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

    $top = $service->getTopTask($tasks, $context);

    // Keyword filtering should keep only the writing-related task (id=1).
    expect($top)->not->toBeNull();
    expect($top['id'])->toBe(1);
});

it('matches task keywords against tag names', function (): void {
    $service = app(TaskPrioritizationService::class);

    $timezone = 'UTC';
    $now = CarbonImmutable::now($timezone);

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

    $top = $service->getTopTask($tasks, $context);

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
    expect($top['type'])->toBe('task');
    expect($top['id'])->toBe(1);
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

it('ranks non-recurring academic tasks above non-recurring non-academic tasks', function (): void {
    $service = app(TaskPrioritizationService::class);
    $now = CarbonImmutable::now('UTC');

    $snapshot = [
        'today' => $now->toDateString(),
        'timezone' => 'UTC',
        'tasks' => [
            [
                'id' => 1,
                'title' => 'Clean room',
                'priority' => 'high',
                'status' => 'to_do',
                'ends_at' => $now->addHours(4)->toIso8601String(),
                'duration_minutes' => 30,
                'is_recurring' => false,
                'subject_name' => null,
                'teacher_name' => null,
                'tags' => ['household'],
            ],
            [
                'id' => 2,
                'title' => 'Study algebra chapter 5',
                'priority' => 'medium',
                'status' => 'to_do',
                'ends_at' => $now->addHours(6)->toIso8601String(),
                'duration_minutes' => 30,
                'is_recurring' => false,
                'subject_name' => 'Mathematics',
                'teacher_name' => 'Mr. Tan',
                'tags' => ['school'],
            ],
        ],
        'events' => [],
        'projects' => [],
    ];

    $ranked = $service->prioritizeFocus($snapshot);

    expect($ranked)->not->toBeEmpty();
    expect($ranked[0]['type'])->toBe('task');
    expect($ranked[0]['id'])->toBe(2);
});

it('ranks non-recurring tasks above recurring academic tasks', function (): void {
    $service = app(TaskPrioritizationService::class);
    $now = CarbonImmutable::now('UTC');

    $snapshot = [
        'today' => $now->toDateString(),
        'timezone' => 'UTC',
        'tasks' => [
            [
                'id' => 1,
                'title' => 'Daily chemistry flashcards',
                'priority' => 'urgent',
                'status' => 'to_do',
                'ends_at' => $now->addHours(2)->toIso8601String(),
                'duration_minutes' => 20,
                'is_recurring' => true,
                'subject_name' => 'Chemistry',
                'teacher_name' => null,
                'tags' => ['study'],
            ],
            [
                'id' => 2,
                'title' => 'Submit project outline',
                'priority' => 'medium',
                'status' => 'to_do',
                'ends_at' => $now->addHours(6)->toIso8601String(),
                'duration_minutes' => 30,
                'is_recurring' => false,
                'subject_name' => null,
                'teacher_name' => null,
                'tags' => [],
            ],
        ],
        'events' => [],
        'projects' => [],
    ];

    $ranked = $service->prioritizeFocus($snapshot);

    expect($ranked)->not->toBeEmpty();
    expect($ranked[0]['id'])->toBe(2);
});

it('ranks recurring academic tasks above recurring non-academic tasks', function (): void {
    $service = app(TaskPrioritizationService::class);
    $now = CarbonImmutable::now('UTC');

    $snapshot = [
        'today' => $now->toDateString(),
        'timezone' => 'UTC',
        'tasks' => [
            [
                'id' => 1,
                'title' => 'Daily stretching',
                'priority' => 'urgent',
                'status' => 'to_do',
                'ends_at' => $now->addHours(1)->toIso8601String(),
                'duration_minutes' => 20,
                'is_recurring' => true,
                'subject_name' => null,
                'teacher_name' => null,
                'tags' => ['health'],
            ],
            [
                'id' => 2,
                'title' => 'Review lecture notes',
                'priority' => 'medium',
                'status' => 'to_do',
                'ends_at' => $now->addHours(5)->toIso8601String(),
                'duration_minutes' => 20,
                'is_recurring' => true,
                'subject_name' => 'Physics',
                'teacher_name' => null,
                'tags' => ['school'],
            ],
        ],
        'events' => [],
        'projects' => [],
    ];

    $ranked = $service->prioritizeFocus($snapshot);

    expect($ranked)->not->toBeEmpty();
    expect($ranked[0]['id'])->toBe(2);
});

it('only promotes events when they are ongoing or within the override window', function (): void {
    $service = app(TaskPrioritizationService::class);
    $now = CarbonImmutable::now('UTC');

    config()->set('task-assistant.prioritization.event_override_window_minutes', 45);

    $snapshot = [
        'today' => $now->toDateString(),
        'timezone' => 'UTC',
        'tasks' => [
            [
                'id' => 1,
                'title' => 'Finish essay draft',
                'priority' => 'high',
                'status' => 'to_do',
                'ends_at' => $now->addHours(3)->toIso8601String(),
                'duration_minutes' => 50,
                'is_recurring' => false,
                'subject_name' => 'English',
                'teacher_name' => null,
                'tags' => ['school'],
            ],
        ],
        'events' => [
            [
                'id' => 10,
                'title' => 'Starts in 50 minutes',
                'starts_at' => $now->addMinutes(50)->toIso8601String(),
                'ends_at' => $now->addMinutes(80)->toIso8601String(),
                'all_day' => false,
                'status' => 'scheduled',
            ],
            [
                'id' => 11,
                'title' => 'Starts in 40 minutes',
                'starts_at' => $now->addMinutes(40)->toIso8601String(),
                'ends_at' => $now->addMinutes(70)->toIso8601String(),
                'all_day' => false,
                'status' => 'scheduled',
            ],
        ],
        'projects' => [],
    ];

    $ranked = $service->prioritizeFocus($snapshot, ['entity_type_preference' => 'task']);
    $ids = collect($ranked)->pluck('id')->all();

    expect($ids)->toContain(11);
    expect($ids)->not->toContain(10);
});

it('does not treat long-started past events as override candidates', function (): void {
    $service = app(TaskPrioritizationService::class);
    $now = CarbonImmutable::now('UTC');

    config()->set('task-assistant.prioritization.event_override_window_minutes', 45);

    $snapshot = [
        'today' => $now->toDateString(),
        'timezone' => 'UTC',
        'tasks' => [
            [
                'id' => 1,
                'title' => 'Read biology chapter',
                'priority' => 'medium',
                'status' => 'to_do',
                'ends_at' => $now->addHours(6)->toIso8601String(),
                'duration_minutes' => 60,
                'is_recurring' => false,
                'subject_name' => 'Biology',
                'teacher_name' => null,
                'tags' => ['school'],
            ],
        ],
        'events' => [
            [
                'id' => 10,
                'title' => 'Started 2 hours ago, ended 1 hour ago',
                'starts_at' => $now->subHours(2)->toIso8601String(),
                'ends_at' => $now->subHour()->toIso8601String(),
                'all_day' => false,
                'status' => 'scheduled',
            ],
        ],
        'projects' => [],
    ];

    $ranked = $service->prioritizeFocus($snapshot, ['entity_type_preference' => 'task']);

    expect($ranked)->not->toBeEmpty();
    expect(collect($ranked)->pluck('id')->all())->not->toContain(10);
    expect($ranked[0]['id'])->toBe(1);
});

it('getTopTask uses real current time semantics for overdue checks', function (): void {
    $service = app(TaskPrioritizationService::class);
    $now = CarbonImmutable::now('UTC');

    $tasks = [
        [
            'id' => 1,
            'title' => 'Overdue earlier today',
            'priority' => 'low',
            'status' => 'to_do',
            'ends_at' => $now->subMinutes(10)->toIso8601String(),
            'duration_minutes' => 20,
            'is_recurring' => false,
        ],
        [
            'id' => 2,
            'title' => 'Due later today',
            'priority' => 'urgent',
            'status' => 'to_do',
            'ends_at' => $now->addHours(2)->toIso8601String(),
            'duration_minutes' => 20,
            'is_recurring' => false,
        ],
    ];

    $top = $service->getTopTask($tasks);

    expect($top)->not->toBeNull();
    expect($top['id'])->toBe(1);
});

it('emits structured explainability contract for ranked focus items', function (): void {
    $service = app(TaskPrioritizationService::class);
    $now = CarbonImmutable::now('UTC');

    $snapshot = [
        'today' => $now->toDateString(),
        'timezone' => 'UTC',
        'tasks' => [
            [
                'id' => 1,
                'title' => 'Submit lab report',
                'priority' => 'high',
                'status' => 'to_do',
                'ends_at' => $now->addHours(2)->toIso8601String(),
                'duration_minutes' => 45,
                'is_recurring' => false,
            ],
        ],
        'events' => [],
        'projects' => [],
    ];

    $ranked = $service->prioritizeFocus($snapshot);

    expect($ranked)->not->toBeEmpty();
    expect($ranked[0]['explainability'] ?? null)->toBeArray();
    expect($ranked[0]['explainability']['reason_code_primary'] ?? null)->toBeString();
    expect($ranked[0]['explainability']['reason_codes_secondary'] ?? null)->toBeArray();
    expect($ranked[0]['explainability']['explainability_facts'] ?? null)->toBeArray();
    expect($ranked[0]['explainability']['narrative_anchor'] ?? null)->toBeArray();
});
