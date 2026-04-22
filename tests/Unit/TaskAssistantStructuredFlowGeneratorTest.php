<?php

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Services\LLM\Prioritization\TaskPrioritizationService;
use App\Services\LLM\Scheduling\TaskAssistantStructuredFlowGenerator;
use Carbon\CarbonImmutable;

it('clears time_constraint when scheduling explicit target entities so due-window does not drop listing items', function (): void {
    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'normalizeSchedulingContextForExplicitTargets');
    $method->setAccessible(true);

    $ctx = ['time_constraint' => 'this_week'];
    $out = $method->invoke($generator, $ctx, [
        'target_entities' => [
            ['entity_type' => 'task', 'entity_id' => 31],
            ['entity_type' => 'task', 'entity_id' => 10],
        ],
    ]);

    expect($out['time_constraint'] ?? null)->toBe('none');

    $unchanged = $method->invoke($generator, $ctx, []);
    expect($unchanged['time_constraint'] ?? null)->toBe('this_week');
});

it('applies priority filters within target_entities task slice', function (): void {
    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'applyContextToSnapshot');
    $method->setAccessible(true);

    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-03-29',
        'tasks' => [
            ['id' => 2, 'title' => 'In target urgent', 'priority' => 'urgent'],
            ['id' => 3, 'title' => 'In target urgent b', 'priority' => 'urgent'],
            ['id' => 4, 'title' => 'Not targeted urgent', 'priority' => 'urgent'],
        ],
        'events' => [],
        'projects' => [],
    ];

    $context = [
        'intent_type' => 'general',
        'priority_filters' => ['urgent'],
        'task_keywords' => [],
        'time_constraint' => 'none',
        'comparison_focus' => null,
        'recurring_requested' => false,
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-03-29',
            'end_date' => '2026-03-29',
            'label' => 'default_today',
        ],
    ];

    $options = [
        'target_entities' => [
            ['entity_type' => 'task', 'entity_id' => 2],
            ['entity_type' => 'task', 'entity_id' => 3],
        ],
    ];

    /** @var array<string, mixed> $out */
    $out = $method->invoke($generator, $snapshot, $context, $options);

    $ids = array_map(fn (array $t): int => (int) ($t['id'] ?? 0), $out['tasks'] ?? []);
    sort($ids);
    expect($ids)->toBe([2, 3]);
});

it('preserves full events list in events_for_busy when targets are task-only', function (): void {
    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'applyContextToSnapshot');
    $method->setAccessible(true);

    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-03-29',
        'tasks' => [
            ['id' => 1, 'title' => 'Targeted', 'priority' => 'medium'],
        ],
        'events' => [
            ['id' => 100, 'title' => 'Meeting', 'starts_at' => '2026-03-29T18:00:00+00:00', 'ends_at' => '2026-03-29T19:00:00+00:00'],
        ],
        'projects' => [],
    ];

    $context = [
        'intent_type' => 'general',
        'priority_filters' => [],
        'task_keywords' => [],
        'time_constraint' => 'none',
        'comparison_focus' => null,
        'recurring_requested' => false,
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-03-29',
            'end_date' => '2026-03-29',
            'label' => 'default_today',
        ],
    ];

    $options = [
        'target_entities' => [
            ['entity_type' => 'task', 'entity_id' => 1],
        ],
    ];

    /** @var array<string, mixed> $out */
    $out = $method->invoke($generator, $snapshot, $context, $options);

    expect($out['events'] ?? [])->toHaveCount(0);
    expect($out['events_for_busy'] ?? [])->toHaveCount(1);
});

it('merges pending busy intervals into events_for_busy snapshot stream', function (): void {
    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'applyContextToSnapshot');
    $method->setAccessible(true);

    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-03-29',
        'tasks' => [],
        'events' => [
            ['id' => 100, 'title' => 'Existing busy', 'starts_at' => '2026-03-29T18:00:00+00:00', 'ends_at' => '2026-03-29T19:00:00+00:00'],
        ],
        'projects' => [],
    ];
    $context = ['schedule_horizon' => ['mode' => 'single_day', 'start_date' => '2026-03-29', 'end_date' => '2026-03-29', 'label' => 'default_today']];
    $options = [
        'pending_busy_intervals' => [
            ['id' => -1, 'title' => 'pending_schedule: task a', 'starts_at' => '2026-03-29T08:00:00+00:00', 'ends_at' => '2026-03-29T08:45:00+00:00'],
            ['id' => -2, 'title' => 'pending_schedule: task b', 'starts_at' => '2026-03-29T09:00:00+00:00', 'ends_at' => '2026-03-29T09:45:00+00:00'],
        ],
    ];

    /** @var array<string, mixed> $out */
    $out = $method->invoke($generator, $snapshot, $context, $options);

    expect($out['events_for_busy'] ?? [])->toHaveCount(3);
});

it('merges missing target task ids from the database into the contextual snapshot', function (): void {
    $user = User::factory()->create();
    $missing = Task::factory()->for($user)->create([
        'title' => 'Merged by target id',
    ]);

    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'mergeMissingTargetTasksForSchedule');
    $method->setAccessible(true);

    $contextualSnapshot = [
        'tasks' => [],
        'schedule_target_skips' => [],
    ];

    /** @var array<string, mixed> $out */
    $out = $method->invoke($generator, $contextualSnapshot, [$missing->id], $user->id);

    $ids = array_map(fn (array $t): int => (int) ($t['id'] ?? 0), $out['tasks'] ?? []);
    expect($ids)->toContain($missing->id);
});

it('resolvePrioritizeScheduleTaskTargets excludes doing tasks when selecting top tasks', function (): void {
    $user = User::factory()->create();
    $thread = \App\Models\TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $timezone = (string) config('app.timezone', 'UTC');
    $tomorrow = CarbonImmutable::now($timezone)->addDay();

    $doingTask = Task::factory()->for($user)->create([
        'title' => 'Implement linked list lab exercises',
        'status' => TaskStatus::Doing,
        'end_datetime' => $tomorrow->setTime(15, 0),
        'duration' => 240,
    ]);

    $todoTask = Task::factory()->for($user)->create([
        'title' => 'Brightspace: DSA Problem Set 3 Submission',
        'status' => TaskStatus::ToDo,
        'end_datetime' => $tomorrow->setTime(15, 59),
        'duration' => 180,
    ]);

    $generator = app(TaskAssistantStructuredFlowGenerator::class);

    $targets = $generator->resolvePrioritizeScheduleTaskTargets(
        thread: $thread,
        userMessageContent: 'Schedule top 1 for later',
        explicitTaskTargets: [],
        countLimit: 1,
    );

    expect($targets)->toHaveCount(1);
    expect((int) ($targets[0]['entity_id'] ?? 0))->toBe($todoTask->id);
    expect((int) ($targets[0]['entity_id'] ?? 0))->not->toBe($doingTask->id);
});

it('schedules a task atomically when duration fits the window', function (): void {
    config([
        'task-assistant.schedule.max_horizon_days' => 5,
    ]);

    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'generateProposalsChunkedSpill');
    $method->setAccessible(true);

    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-03-30',
        'time_window' => ['start' => '18:00', 'end' => '22:00:00'],
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-03-30',
            'end_date' => '2026-03-30',
            'label' => 'default_today',
        ],
        'tasks' => [
            [
                'id' => 42,
                'title' => 'Deep work project',
                'priority' => 'medium',
                'duration_minutes' => 240,
                'ends_at' => null,
                'is_recurring' => false,
            ],
        ],
        'events' => [],
        'events_for_busy' => [
            [
                'id' => 501,
                'title' => 'Busy morning tail',
                'starts_at' => '2026-03-30T12:00:00+00:00',
                'ends_at' => '2026-03-30T13:00:00+00:00',
            ],
        ],
        'projects' => [],
    ];

    $context = [
        'schedule_horizon' => $snapshot['schedule_horizon'],
    ];

    [$proposals, $digest] = $method->invoke($generator, $snapshot, $context, 10);

    expect(count($proposals))->toBe(1);
    expect(count($digest['days_used'] ?? []))->toBe(1);
    expect($proposals[0]['schedule_apply_as'] ?? null)->toBe('update_task');
    expect((int) ($proposals[0]['duration_minutes'] ?? 0))->toBe(240);
    expect((string) ($proposals[0]['title'] ?? ''))->not->toContain('(part');
    expect(is_array($digest['unplaced_units'] ?? null) ? count($digest['unplaced_units']) : 0)->toBe(0);
});

it('uses task duration to bound busy end when due date is much later', function (): void {
    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'resolveTaskBusyEnd');
    $method->setAccessible(true);

    $start = new DateTimeImmutable('2026-04-23T08:00:00+00:00');
    $end = $method->invoke(
        $generator,
        [
            'ends_at' => '2026-04-26T19:01:00+00:00',
            'duration_minutes' => 45,
        ],
        $start,
        new DateTimeZone('UTC')
    );

    expect($end)->toBeInstanceOf(DateTimeImmutable::class);
    expect($end->format('Y-m-d H:i'))->toBe('2026-04-23 08:45');
});

it('records unplaced units when proposal count_limit is reached', function (): void {
    config([
        'task-assistant.schedule.max_horizon_days' => 3,
    ]);

    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'generateProposalsChunkedSpill');
    $method->setAccessible(true);

    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-03-30',
        'time_window' => ['start' => '18:00', 'end' => '22:00:00'],
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-03-30',
            'end_date' => '2026-03-30',
            'label' => 'default_today',
        ],
        'tasks' => [
            [
                'id' => 7,
                'title' => 'Task 1',
                'priority' => 'high',
                'duration_minutes' => 60,
                'ends_at' => null,
                'is_recurring' => false,
            ],
            [
                'id' => 8,
                'title' => 'Task 2',
                'priority' => 'high',
                'duration_minutes' => 60,
                'ends_at' => null,
                'is_recurring' => false,
            ],
            [
                'id' => 9,
                'title' => 'Task 3',
                'priority' => 'high',
                'duration_minutes' => 60,
                'ends_at' => null,
                'is_recurring' => false,
            ],
        ],
        'events' => [],
        'events_for_busy' => [
            [
                'id' => 601,
                'title' => 'Busy morning',
                'starts_at' => '2026-03-30T08:00:00+00:00',
                'ends_at' => '2026-03-30T11:30:00+00:00',
            ],
        ],
        'projects' => [],
    ];

    $context = ['schedule_horizon' => $snapshot['schedule_horizon']];

    [, $digest] = $method->invoke($generator, $snapshot, $context, 2);

    $unplacedReasons = array_map(
        fn (array $u): string => (string) ($u['reason'] ?? ''),
        is_array($digest['unplaced_units'] ?? null) ? $digest['unplaced_units'] : []
    );
    expect($unplacedReasons)->toContain('count_limit');
});

it('builds structured schedule explainability records alongside legacy rationale text', function (): void {
    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'buildScheduleExplainability');
    $method->setAccessible(true);

    $snapshot = [
        'time_window' => ['start' => '18:00', 'end' => '22:00'],
        'schedule_horizon' => ['start_date' => '2026-04-22', 'end_date' => '2026-04-24'],
    ];
    $context = [];
    $proposals = [[
        'title' => 'Practice coding interview problems',
        'start_datetime' => '2026-04-22T18:00:00+00:00',
    ]];
    $digest = [
        'unplaced_units' => [[
            'title' => 'Review DSA notes',
            'reason' => 'window_conflict',
        ]],
        'fallback_mode' => 'auto_relaxed_today_or_tomorrow',
    ];

    $result = $method->invoke($generator, $snapshot, $context, $proposals, $digest, []);

    expect($result['requested_horizon_label'] ?? null)->toBeString();
    expect($result['requested_window_display_label'] ?? null)->toBeString();
    expect($result['has_explicit_clock_time'] ?? null)->toBeBool();
    expect($result['blocking_section_title'] ?? null)->toBeString();
    expect($result['window_selection_struct'] ?? null)->toBeArray();
    expect($result['window_selection_struct']['reason_code_primary'] ?? null)->toBeString();
    expect($result['ordering_rationale_struct'] ?? null)->toBeArray();
    expect($result['ordering_rationale_struct'][0]['fit_reason_code'] ?? null)->toBeString();
    expect($result['blocking_reasons_struct'] ?? null)->toBeArray();
    expect($result['blocking_reasons_struct'][0]['block_reason_code'] ?? null)->toBeString();
});

it('uses explicit task datetime ranges for unplaced blocker rows', function (): void {
    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'buildScheduleExplainability');
    $method->setAccessible(true);

    $snapshot = [
        'time_window' => ['start' => '08:00', 'end' => '22:00'],
        'schedule_horizon' => ['start_date' => '2026-04-22', 'end_date' => '2026-04-22', 'label' => 'tomorrow'],
        'tasks' => [[
            'id' => 10,
            'title' => 'Brightspace submission',
            'starts_at' => '2026-04-22T09:00:00+00:00',
            'ends_at' => '2026-04-22T11:00:00+00:00',
        ]],
    ];
    $digest = [
        'unplaced_units' => [[
            'entity_type' => 'task',
            'entity_id' => 10,
            'title' => 'Brightspace submission',
            'reason' => 'horizon_exhausted',
        ]],
    ];

    $result = $method->invoke($generator, $snapshot, [], [], $digest, []);
    $blocking = is_array($result['blocking_reasons'] ?? null) ? $result['blocking_reasons'] : [];

    expect($blocking)->not->toBe([]);
    expect((string) ($blocking[0]['blocked_window'] ?? ''))
        ->toContain('Apr 22, 2026')
        ->toContain('9:00 AM')
        ->toContain('11:00 AM');
});

it('does not truncate too far when the available same-day window is too small', function (): void {
    config([
        'task-assistant.schedule.max_horizon_days' => 2,
    ]);

    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'generateProposalsChunkedSpill');
    $method->setAccessible(true);

    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-03-30',
        'time_window' => ['start' => '18:00', 'end' => '19:00:00'],
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-03-30',
            'end_date' => '2026-03-30',
            'label' => 'default_today',
        ],
        'tasks' => [
            [
                'id' => 99,
                'title' => 'Too long task',
                'priority' => 'urgent',
                'duration_minutes' => 300,
                'ends_at' => null,
                'is_recurring' => false,
            ],
        ],
        'events' => [],
        'events_for_busy' => [
            [
                'id' => 601,
                'title' => 'Busy morning',
                'starts_at' => '2026-03-30T08:00:00+00:00',
                'ends_at' => '2026-03-30T11:30:00+00:00',
            ],
        ],
        'projects' => [],
    ];

    $context = ['schedule_horizon' => $snapshot['schedule_horizon']];
    $context['time_window_strict'] = true;

    [$proposals, $digest] = $method->invoke($generator, $snapshot, $context, 10);

    expect($proposals)->toBe([]);
    expect(is_array($digest['partial_units'] ?? null) ? count($digest['partial_units']) : 0)->toBe(0);
    expect(is_array($digest['unplaced_units'] ?? null) ? count($digest['unplaced_units']) : 0)->toBeGreaterThan(0);
});

it('places work on later days within range horizon when first day has no room', function (): void {
    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'generateProposalsChunkedSpill');
    $method->setAccessible(true);

    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-04-02',
        'time_window' => ['start' => '13:00', 'end' => '22:00'],
        'schedule_horizon' => [
            'mode' => 'range',
            'start_date' => '2026-04-02',
            'end_date' => '2026-04-05',
            'label' => 'this week',
        ],
        'tasks' => [
            [
                'id' => 42,
                'title' => 'Later this week task',
                'priority' => 'high',
                'duration_minutes' => 60,
                'ends_at' => null,
                'is_recurring' => false,
            ],
        ],
        'events' => [],
        'events_for_busy' => [
            [
                'id' => 9001,
                'title' => 'Full day block',
                'starts_at' => '2026-04-02T13:00:00+00:00',
                'ends_at' => '2026-04-02T22:00:00+00:00',
            ],
        ],
        'projects' => [],
    ];

    $context = [
        'schedule_horizon' => $snapshot['schedule_horizon'],
        'time_window_strict' => false,
    ];

    [$proposals, $digest] = $method->invoke($generator, $snapshot, $context, 10);

    expect($proposals)->not->toBe([]);
    expect((string) ($proposals[0]['title'] ?? ''))->toBe('Later this week task');
    expect((string) ($proposals[0]['start_datetime'] ?? ''))->toContain('2026-04-03');
    expect(is_array($digest['days_used'] ?? null) ? $digest['days_used'] : [])->toContain('2026-04-03');
});

it('default asap mode prefers same-day feasible slot over tomorrow morning', function (): void {
    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'generateProposalsChunkedSpill');
    $method->setAccessible(true);

    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-04-12',
        'now' => '2026-04-12T16:30:00+00:00',
        'time_window' => ['start' => '08:00', 'end' => '22:00:00'],
        'schedule_horizon' => [
            'mode' => 'range',
            'start_date' => '2026-04-12',
            'end_date' => '2026-04-13',
            'label' => 'smart_default_spread',
        ],
        'tasks' => [[
            'id' => 42,
            'title' => 'Most important task',
            'priority' => 'urgent',
            'duration_minutes' => 60,
            'ends_at' => null,
            'is_recurring' => false,
            'complexity' => 'high',
        ]],
        'events' => [],
        'events_for_busy' => [],
        'projects' => [],
    ];

    $context = [
        'schedule_horizon' => $snapshot['schedule_horizon'],
        'default_asap_mode' => true,
    ];

    [$proposals] = $method->invoke($generator, $snapshot, $context, 1);

    expect($proposals)->toHaveCount(1);
    expect(str_starts_with((string) ($proposals[0]['start_datetime'] ?? ''), '2026-04-12T'))->toBeTrue();
});

it('default asap mode falls back to tomorrow when today has no feasible window', function (): void {
    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'generateProposalsChunkedSpill');
    $method->setAccessible(true);

    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-04-12',
        'now' => '2026-04-12T16:30:00+00:00',
        'time_window' => ['start' => '08:00', 'end' => '22:00:00'],
        'schedule_horizon' => [
            'mode' => 'range',
            'start_date' => '2026-04-12',
            'end_date' => '2026-04-13',
            'label' => 'smart_default_spread',
        ],
        'tasks' => [[
            'id' => 52,
            'title' => 'Most important task',
            'priority' => 'urgent',
            'duration_minutes' => 60,
            'ends_at' => null,
            'is_recurring' => false,
            'complexity' => 'high',
        ]],
        'events' => [],
        'events_for_busy' => [[
            'id' => 801,
            'title' => 'Today fully blocked',
            'starts_at' => '2026-04-12T08:00:00+00:00',
            'ends_at' => '2026-04-12T22:00:00+00:00',
        ]],
        'projects' => [],
    ];

    $context = [
        'schedule_horizon' => $snapshot['schedule_horizon'],
        'default_asap_mode' => true,
    ];

    [$proposals] = $method->invoke($generator, $snapshot, $context, 1);

    expect($proposals)->toHaveCount(1);
    expect(str_starts_with((string) ($proposals[0]['start_datetime'] ?? ''), '2026-04-13T'))->toBeTrue();
});

it('does not place new proposals over already scheduled task windows', function (): void {
    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'generateProposalsChunkedSpill');
    $method->setAccessible(true);

    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-04-12',
        'time_window' => ['start' => '08:00', 'end' => '18:00:00'],
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-04-12',
            'end_date' => '2026-04-12',
            'label' => 'default_today',
        ],
        'tasks' => [
            [
                'id' => 1,
                'title' => 'Already scheduled',
                'priority' => 'high',
                'duration_minutes' => 60,
                'starts_at' => '2026-04-12T10:00:00+00:00',
                'ends_at' => '2026-04-12T11:00:00+00:00',
                'is_recurring' => false,
            ],
            [
                'id' => 2,
                'title' => 'New task',
                'priority' => 'urgent',
                'duration_minutes' => 60,
                'starts_at' => null,
                'ends_at' => null,
                'is_recurring' => false,
            ],
        ],
        'events' => [],
        'events_for_busy' => [],
        'projects' => [],
    ];

    $context = ['schedule_horizon' => $snapshot['schedule_horizon']];
    [$proposals] = $method->invoke($generator, $snapshot, $context, 1);

    expect($proposals)->toHaveCount(1);
    $start = new DateTimeImmutable((string) ($proposals[0]['start_datetime'] ?? ''));
    $end = new DateTimeImmutable((string) ($proposals[0]['end_datetime'] ?? ''));
    $blockedStart = new DateTimeImmutable('2026-04-12T10:00:00+00:00');
    $blockedEnd = new DateTimeImmutable('2026-04-12T11:00:00+00:00');
    $overlap = $start < $blockedEnd && $end > $blockedStart;
    expect($overlap)->toBeFalse();
});

it('uses adaptive tomorrow fallback when narrow non-strict window cannot place any unit', function (): void {
    config([
        'task-assistant.schedule.max_horizon_days' => 2,
    ]);

    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'generateProposalsChunkedSpill');
    $method->setAccessible(true);

    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-03-30',
        'time_window' => ['start' => '21:45', 'end' => '22:00:00'],
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-03-30',
            'end_date' => '2026-03-30',
            'label' => 'default_today',
        ],
        'tasks' => [
            [
                'id' => 199,
                'title' => 'Too long for tiny window',
                'priority' => 'urgent',
                'duration_minutes' => 300,
                'ends_at' => null,
                'is_recurring' => false,
            ],
        ],
        'events' => [],
        'events_for_busy' => [
            [
                'id' => 501,
                'title' => 'Busy morning tail',
                'starts_at' => '2026-03-30T12:00:00+00:00',
                'ends_at' => '2026-03-30T13:00:00+00:00',
            ],
        ],
        'projects' => [],
    ];

    $context = ['schedule_horizon' => $snapshot['schedule_horizon'], 'time_window_strict' => false];

    [$proposals, $digest] = $method->invoke($generator, $snapshot, $context, 10);

    expect((string) ($proposals[0]['title'] ?? ''))->not->toBe('No schedulable items found');
    expect((string) ($digest['fallback_mode'] ?? ''))->toBe('auto_relaxed_today_or_tomorrow');
    expect((string) ($snapshot['today'] ?? ''))->toBe('2026-03-30');
    expect((string) ($proposals[0]['start_datetime'] ?? ''))->toContain('2026-03-31');
});

it('places top ranked task in morning by shrinking to 80 percent minimum when needed', function (): void {
    config([
        'task-assistant.schedule.max_horizon_days' => 2,
    ]);

    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'generateProposalsChunkedSpill');
    $method->setAccessible(true);

    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-03-30',
        'time_window' => ['start' => '08:00', 'end' => '22:00:00'],
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-03-30',
            'end_date' => '2026-03-30',
            'label' => 'default_today',
        ],
        'tasks' => [
            [
                'id' => 1001,
                'title' => 'Top long task',
                'priority' => 'urgent',
                'duration_minutes' => 300,
                'ends_at' => null,
                'is_recurring' => false,
            ],
        ],
        'events' => [],
        'events_for_busy' => [],
        'projects' => [],
    ];

    $context = ['schedule_horizon' => $snapshot['schedule_horizon']];

    [$proposals, $digest] = $method->invoke($generator, $snapshot, $context, 10);

    expect((int) ($proposals[0]['entity_id'] ?? 0))->toBe(1001);
    expect((string) ($proposals[0]['start_datetime'] ?? ''))->toContain('T08:00:00');
    expect((int) ($proposals[0]['placed_minutes'] ?? 0))->toBe(240);
    expect((int) ($proposals[0]['requested_minutes'] ?? 0))->toBe(300);
    expect((string) ($proposals[0]['placement_reason'] ?? ''))->toBe('top1_morning_shrink');
    expect((string) data_get($digest, 'partial_units.0.reason', ''))->toBe('top1_morning_shrink');
});

it('does not force top ranked morning placement when 80 percent minimum cannot fit', function (): void {
    config([
        'task-assistant.schedule.max_horizon_days' => 2,
    ]);

    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'generateProposalsChunkedSpill');
    $method->setAccessible(true);

    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-03-30',
        'time_window' => ['start' => '08:00', 'end' => '22:00:00'],
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-03-30',
            'end_date' => '2026-03-30',
            'label' => 'default_today',
        ],
        'tasks' => [
            [
                'id' => 2001,
                'title' => 'Top long task',
                'priority' => 'urgent',
                'duration_minutes' => 300,
                'ends_at' => null,
                'is_recurring' => false,
            ],
        ],
        'events' => [
            [
                'id' => 601,
                'title' => 'Busy morning',
                'starts_at' => '2026-03-30T08:00:00+00:00',
                'ends_at' => '2026-03-30T11:30:00+00:00',
            ],
        ],
        'events_for_busy' => [
            [
                'id' => 601,
                'title' => 'Busy morning',
                'starts_at' => '2026-03-30T08:00:00+00:00',
                'ends_at' => '2026-03-30T11:30:00+00:00',
            ],
        ],
        'projects' => [],
    ];

    $context = ['schedule_horizon' => $snapshot['schedule_horizon']];

    [$proposals] = $method->invoke($generator, $snapshot, $context, 10);

    expect((int) ($proposals[0]['entity_id'] ?? 0))->toBe(2001);
    expect((string) ($proposals[0]['start_datetime'] ?? ''))->toContain('T13:00:00');
    expect((string) ($proposals[0]['placement_reason'] ?? ''))->not->toBe('top1_morning_shrink');
});

it('truncates same-day task duration when enough time remains within the window', function (): void {
    config([
        'task-assistant.schedule.max_horizon_days' => 2,
    ]);

    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'generateProposalsChunkedSpill');
    $method->setAccessible(true);

    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-03-30',
        'time_window' => ['start' => '15:00', 'end' => '18:00:00'],
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-03-30',
            'end_date' => '2026-03-30',
            'label' => 'default_today',
        ],
        'tasks' => [
            [
                'id' => 100,
                'title' => 'Long task',
                'priority' => 'urgent',
                'duration_minutes' => 300,
                'ends_at' => null,
                'is_recurring' => false,
            ],
        ],
        'events' => [],
        'events_for_busy' => [],
        'projects' => [],
    ];

    $context = ['schedule_horizon' => $snapshot['schedule_horizon']];

    [$proposals, $digest] = $method->invoke($generator, $snapshot, $context, 10);

    expect((bool) ($proposals[0]['partial'] ?? false))->toBeTrue();
    expect((int) ($proposals[0]['requested_minutes'] ?? 0))->toBe(300);
    expect((int) ($proposals[0]['placed_minutes'] ?? 0))->toBe(180);
    expect(is_array($digest['partial_units'] ?? null) ? count($digest['partial_units']) : 0)->toBe(1);
});

it('allows partial placement only for top-ranked item in top-N requests and marks shortfall', function (): void {
    config([
        'task-assistant.schedule.max_horizon_days' => 2,
        'task-assistant.schedule.partial_policy' => 'top1_only',
        'task-assistant.schedule.top_n_shortfall_policy' => 'confirm_if_shortfall',
    ]);

    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'generateProposalsChunkedSpill');
    $method->setAccessible(true);

    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-03-30',
        'time_window' => ['start' => '15:00', 'end' => '18:00:00'],
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-03-30',
            'end_date' => '2026-03-30',
            'label' => 'tomorrow',
        ],
        'tasks' => [
            ['id' => 100, 'title' => 'Top long task', 'priority' => 'urgent', 'duration_minutes' => 300, 'ends_at' => null, 'is_recurring' => false],
            ['id' => 101, 'title' => 'Second task', 'priority' => 'high', 'duration_minutes' => 75, 'ends_at' => null, 'is_recurring' => false],
            ['id' => 102, 'title' => 'Third task', 'priority' => 'medium', 'duration_minutes' => 20, 'ends_at' => null, 'is_recurring' => false],
        ],
        'events' => [],
        'events_for_busy' => [],
        'projects' => [],
    ];

    $context = ['schedule_horizon' => $snapshot['schedule_horizon']];
    $scheduleOptions = [
        'count_limit' => 3,
        'explicit_requested_count' => 3,
        'time_window_hint' => 'later_afternoon',
    ];

    [$proposals, $digest] = $method->invoke($generator, $snapshot, $context, 3, $scheduleOptions);

    expect($proposals)->toHaveCount(1);
    expect((bool) ($proposals[0]['partial'] ?? false))->toBeTrue();
    expect((int) ($proposals[0]['priority_rank'] ?? 0))->toBe(1);
    expect((bool) ($digest['top_n_shortfall'] ?? false))->toBeTrue();
    expect((int) ($digest['requested_count'] ?? 0))->toBe(3);
    expect((int) ($digest['count_shortfall'] ?? 0))->toBeGreaterThan(0);
});

it('does not flag top-N shortfall when request count is system default and all candidates are placed', function (): void {
    config([
        'task-assistant.schedule.max_horizon_days' => 7,
        'task-assistant.schedule.top_n_shortfall_policy' => 'confirm_if_shortfall',
    ]);

    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'generateProposalsChunkedSpill');
    $method->setAccessible(true);

    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-03-30',
        'time_window' => ['start' => '13:00', 'end' => '22:00:00'],
        'schedule_horizon' => [
            'mode' => 'range',
            'start_date' => '2026-03-30',
            'end_date' => '2026-04-05',
            'label' => 'this_week',
        ],
        'tasks' => [
            ['id' => 100, 'title' => 'Task A', 'priority' => 'high', 'duration_minutes' => 60, 'ends_at' => null, 'is_recurring' => false],
            ['id' => 101, 'title' => 'Task B', 'priority' => 'medium', 'duration_minutes' => 60, 'ends_at' => null, 'is_recurring' => false],
        ],
        'events' => [],
        'events_for_busy' => [],
        'projects' => [],
    ];

    $context = ['schedule_horizon' => $snapshot['schedule_horizon']];
    $scheduleOptions = [
        'count_limit' => 3,
        'time_window_hint' => 'later',
    ];

    [$proposals, $digest] = $method->invoke($generator, $snapshot, $context, 3, $scheduleOptions);

    expect($proposals)->toHaveCount(2);
    expect((string) ($digest['requested_count_source'] ?? ''))->toBe('system_default');
    expect((int) ($digest['requested_count'] ?? 0))->toBe(2);
    expect((bool) ($digest['top_n_shortfall'] ?? false))->toBeFalse();
    expect((int) ($digest['count_shortfall'] ?? -1))->toBe(0);
});

it('still subtracts calendar busy from events_for_busy for task-only targets', function (): void {
    config([
        'task-assistant.schedule.max_horizon_days' => 2,
    ]);

    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $apply = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'applyContextToSnapshot');
    $apply->setAccessible(true);
    $place = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'generateProposalsChunkedSpill');
    $place->setAccessible(true);

    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-03-30',
        'tasks' => [
            ['id' => 1, 'title' => 'Task A', 'priority' => 'high', 'duration_minutes' => 60, 'ends_at' => null, 'is_recurring' => false],
        ],
        'events' => [
            [
                'id' => 900,
                'title' => 'Busy',
                'starts_at' => '2026-03-30T18:00:00+00:00',
                'ends_at' => '2026-03-30T21:30:00+00:00',
            ],
        ],
        'projects' => [],
    ];

    $context = [
        'intent_type' => 'general',
        'priority_filters' => [],
        'task_keywords' => [],
        'time_constraint' => 'none',
        'comparison_focus' => null,
        'recurring_requested' => false,
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-03-30',
            'end_date' => '2026-03-30',
            'label' => 'default_today',
        ],
    ];

    $options = [
        'target_entities' => [['entity_type' => 'task', 'entity_id' => 1]],
        'time_window_hint' => 'evening',
    ];

    /** @var array<string, mixed> $ctxSnap */
    $ctxSnap = $apply->invoke($generator, $snapshot, $context, $options);

    [$proposals, $digest] = $place->invoke($generator, $ctxSnap, $context, 5);

    expect($ctxSnap['events'] ?? [])->toHaveCount(0);
    expect($ctxSnap['events_for_busy'] ?? [])->not->toHaveCount(0);

    expect($proposals)->not->toBe([]);
    $title = (string) ($proposals[0]['title'] ?? '');
    if ($title === 'No schedulable items found') {
        $firstStart = (string) ($proposals[0]['start_datetime'] ?? '');
        expect($firstStart)->toContain('2026-03-30');
    } else {
        expect($title)->toBe('Task A');
        $firstStart = (string) ($proposals[0]['start_datetime'] ?? '');
        expect($firstStart)->toContain('2026-03-31');
    }
});

it('orders scheduling candidates by prioritizeFocus ranking', function (): void {
    CarbonImmutable::setTestNow('2026-03-30T10:00:00+00:00');

    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $prioritization = app(TaskPrioritizationService::class);

    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-03-30',
        'time_window' => ['start' => '00:00', 'end' => '23:59:59'],
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-03-30',
            'end_date' => '2026-03-30',
            'label' => 'default_today',
        ],
        'tasks' => [
            [
                'id' => 1,
                'title' => 'Overdue task',
                'priority' => 'medium',
                'duration_minutes' => 30,
                'ends_at' => '2026-03-29T12:00:00+00:00',
                'is_recurring' => false,
                'status' => null,
            ],
            [
                'id' => 2,
                'title' => 'Due later (urgent)',
                'priority' => 'urgent',
                'duration_minutes' => 30,
                'ends_at' => '2026-03-30T20:00:00+00:00',
                'is_recurring' => false,
                'status' => null,
            ],
            [
                'id' => 3,
                'title' => 'No due date (low)',
                'priority' => 'low',
                'duration_minutes' => 30,
                'ends_at' => null,
                'is_recurring' => false,
                'status' => null,
            ],
        ],
        'events' => [],
        'events_for_busy' => [],
        'projects' => [],
    ];

    $context = [
        'intent_type' => 'general',
        'priority_filters' => [],
        'task_keywords' => [],
        'time_constraint' => 'none',
        'comparison_focus' => null,
        'recurring_requested' => false,
        'domain_focus' => null,
        'entity_type_preference' => 'task',
        'schedule_horizon' => $snapshot['schedule_horizon'],
    ];

    $ranked = $prioritization->prioritizeFocus($snapshot, $context);
    $expectedTopId = (int) ($ranked[0]['id'] ?? 0);
    expect($expectedTopId)->toBeGreaterThan(0);

    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'generateProposalsChunkedSpill');
    $method->setAccessible(true);

    /** @var array{0: array<int, array<string, mixed>>, 1: array<string, mixed>} $out */
    $out = $method->invoke($generator, $snapshot, $context, 10);

    $proposals = $out[0];
    expect($proposals)->not->toBe([]);
    expect($proposals[0]['entity_type'] ?? null)->toBe('task');
    expect((int) ($proposals[0]['entity_id'] ?? 0))->toBe($expectedTopId);
});

it('reserves proportional gaps between placed blocks (no trailing gap after count_limit)', function (): void {
    config([
        'task-assistant.schedule.max_horizon_days' => 3,
    ]);

    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'generateProposalsChunkedSpill');
    $method->setAccessible(true);

    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-03-30',
        'time_window' => ['start' => '00:00', 'end' => '23:59:59'],
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-03-30',
            'end_date' => '2026-03-30',
            'label' => 'default_today',
        ],
        'tasks' => [
            [
                'id' => 1,
                'title' => 'Task A',
                'priority' => 'medium',
                'duration_minutes' => 60,
                'ends_at' => null,
                'is_recurring' => false,
            ],
            [
                'id' => 2,
                'title' => 'Task B',
                'priority' => 'medium',
                'duration_minutes' => 60,
                'ends_at' => null,
                'is_recurring' => false,
            ],
            [
                'id' => 3,
                'title' => 'Task C',
                'priority' => 'medium',
                'duration_minutes' => 60,
                'ends_at' => null,
                'is_recurring' => false,
            ],
        ],
        'events' => [],
        'events_for_busy' => [],
        'projects' => [],
    ];

    $context = ['schedule_horizon' => $snapshot['schedule_horizon']];

    /** @var array{0: array<int, array<string, mixed>>, 1: array<string, mixed>} $out */
    $out = $method->invoke($generator, $snapshot, $context, 3);

    $proposals = $out[0];
    expect(count($proposals))->toBe(3);

    usort($proposals, static function (array $a, array $b): int {
        return strcmp((string) ($a['start_datetime'] ?? ''), (string) ($b['start_datetime'] ?? ''));
    });

    $gapMinutes = 15; // <=60 minutes => 15 minutes (per generator mapping)

    $end0 = new DateTimeImmutable((string) ($proposals[0]['end_datetime'] ?? ''));
    $start1 = new DateTimeImmutable((string) ($proposals[1]['start_datetime'] ?? ''));
    $gapSeconds = $start1->getTimestamp() - $end0->getTimestamp();
    expect($gapSeconds)->toBeIn([0, $gapMinutes * 60]);

    $end1 = new DateTimeImmutable((string) ($proposals[1]['end_datetime'] ?? ''));
    $start2 = new DateTimeImmutable((string) ($proposals[2]['start_datetime'] ?? ''));
    expect($start2 >= $end1)->toBeTrue();
});

it('spills placements across multiple days when horizon is a multi-day range and one day cannot hold all atomic blocks', function (): void {
    config([
        'task-assistant.schedule.max_horizon_days' => 14,
    ]);

    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'generateProposalsChunkedSpill');
    $method->setAccessible(true);

    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-03-30',
        'now' => '2026-03-30T08:00:00+00:00',
        'time_window' => ['start' => '08:00', 'end' => '22:00:00'],
        'schedule_horizon' => [
            'mode' => 'range',
            'start_date' => '2026-03-30',
            'end_date' => '2026-04-01',
            'label' => 'smart_default_spread',
        ],
        'tasks' => [
            [
                'id' => 101,
                'title' => 'Large block A',
                'priority' => 'high',
                'duration_minutes' => 300,
                'ends_at' => null,
                'is_recurring' => false,
            ],
            [
                'id' => 102,
                'title' => 'Large block B',
                'priority' => 'high',
                'duration_minutes' => 300,
                'ends_at' => null,
                'is_recurring' => false,
            ],
            [
                'id' => 103,
                'title' => 'Large block C',
                'priority' => 'high',
                'duration_minutes' => 300,
                'ends_at' => null,
                'is_recurring' => false,
            ],
        ],
        'events' => [],
        'events_for_busy' => [],
        'projects' => [],
    ];

    $context = ['schedule_horizon' => $snapshot['schedule_horizon']];

    /** @var array{0: array<int, array<string, mixed>>, 1: array<string, mixed>} $out */
    $out = $method->invoke($generator, $snapshot, $context, 10);

    $digest = $out[1];
    $daysUsed = is_array($digest['days_used'] ?? null) ? $digest['days_used'] : [];

    expect(count($daysUsed))->toBeGreaterThanOrEqual(2);
});

it('treats events with missing end time as busy using fallback duration', function (): void {
    config([
        'task-assistant.schedule.event_fallback_duration_minutes' => 60,
    ]);

    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'generateProposalsChunkedSpill');
    $method->setAccessible(true);

    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-04-10',
        'time_window' => ['start' => '18:00', 'end' => '22:00'],
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-04-10',
            'end_date' => '2026-04-10',
            'label' => 'today',
        ],
        'tasks' => [
            ['id' => 1, 'title' => 'Fallback busy check', 'priority' => 'high', 'duration_minutes' => 60, 'ends_at' => null, 'is_recurring' => false],
        ],
        'events' => [],
        'events_for_busy' => [
            ['id' => 7001, 'title' => 'Open event', 'starts_at' => '2026-04-10T18:00:00+00:00', 'ends_at' => null, 'all_day' => false],
        ],
        'projects' => [],
    ];

    $context = ['schedule_horizon' => $snapshot['schedule_horizon']];

    [$proposals] = $method->invoke($generator, $snapshot, $context, 1);

    expect($proposals)->not->toBe([]);
    expect((string) ($proposals[0]['start_datetime'] ?? ''))->toContain('T19:00:00');
});

it('allows scheduling through lunch when lunch block is disabled in user preferences', function (): void {
    config([
        'task-assistant.schedule.lunch_block.enabled' => true,
        'task-assistant.schedule.lunch_block.start' => '12:00',
        'task-assistant.schedule.lunch_block.end' => '13:00',
    ]);

    $generator = app(TaskAssistantStructuredFlowGenerator::class);
    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'generateProposalsChunkedSpill');
    $method->setAccessible(true);

    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-04-10',
        'time_window' => ['start' => '12:00', 'end' => '14:00'],
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-04-10',
            'end_date' => '2026-04-10',
            'label' => 'today',
        ],
        'tasks' => [
            ['id' => 2, 'title' => 'Lunch slot task', 'priority' => 'high', 'duration_minutes' => 30, 'ends_at' => null, 'is_recurring' => false],
        ],
        'events' => [],
        'events_for_busy' => [],
        'projects' => [],
        'schedule_preferences' => [
            'lunch_block' => [
                'enabled' => false,
                'start' => '12:00',
                'end' => '13:00',
            ],
        ],
    ];

    $context = ['schedule_horizon' => $snapshot['schedule_horizon']];

    [$proposals] = $method->invoke($generator, $snapshot, $context, 1);

    expect($proposals)->not->toBe([]);
    expect((string) ($proposals[0]['start_datetime'] ?? ''))->toContain('T12:00:00');
});
