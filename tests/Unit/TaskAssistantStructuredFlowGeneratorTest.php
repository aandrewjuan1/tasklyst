<?php

use App\Services\LLM\Scheduling\TaskAssistantStructuredFlowGenerator;

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
