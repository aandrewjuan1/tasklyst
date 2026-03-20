<?php

use App\Services\LLM\Prioritization\TaskPrioritizationService;
use App\Services\LLM\TaskAssistant\TaskAssistantService;

it('ranks overdue tasks above due-today and due-tomorrow tasks', function (): void {
    $service = app(TaskAssistantService::class);

    $method = new \ReflectionMethod(TaskAssistantService::class, 'rankTopTasks');
    $method->setAccessible(true);

    $now = now();

    $snapshotTasks = [
        [
            'id' => 1,
            'title' => 'Overdue task',
            // Use a far-enough timestamp to avoid any timezone/format edge cases
            // making this task not “overdue”.
            'ends_at' => $now->copy()->subYear()->format('c'),
            'priority' => 'low',
            'status' => null,
            'duration_minutes' => 30,
        ],
        [
            'id' => 2,
            'title' => 'Due today urgent',
            'ends_at' => $now->format('c'),
            'priority' => 'urgent',
            'status' => null,
            'duration_minutes' => 60,
        ],
        [
            'id' => 3,
            'title' => 'Due today medium',
            'ends_at' => $now->format('c'),
            'priority' => 'medium',
            'status' => null,
            'duration_minutes' => 45,
        ],
        [
            'id' => 4,
            'title' => 'Due tomorrow high',
            'ends_at' => $now->copy()->addDay()->format('c'),
            'priority' => 'high',
            'status' => null,
            'duration_minutes' => 10,
        ],
    ];

    $items = $method->invoke($service, $snapshotTasks, 3, []);

    expect($items)->toHaveCount(3);

    $expectedRanked = app(TaskPrioritizationService::class)->prioritizeFocus([
        'today' => $now->toDateString(),
        'timezone' => config('app.timezone', 'UTC'),
        'tasks' => $snapshotTasks,
        'events' => [],
        'projects' => [],
    ], []);

    $expectedTaskCandidates = array_values(array_filter(
        $expectedRanked,
        fn (array $c): bool => ($c['type'] ?? null) === 'task'
    ));

    $expectedTopTaskIds = array_map(
        fn (array $c): int => (int) ($c['id'] ?? 0),
        array_slice($expectedTaskCandidates, 0, 3)
    );

    $actualTaskIds = array_map(
        fn (array $item): int => (int) ($item['task_id'] ?? 0),
        $items
    );

    expect($actualTaskIds)->toBe($expectedTopTaskIds);

    foreach ($items as $item) {
        expect($item['reason'] ?? null)->not->toBeEmpty();
        $nextSteps = $item['next_steps'] ?? [];
        expect($nextSteps)->toBeArray();
        expect(count($nextSteps))->toBeGreaterThan(0);
        expect(count($nextSteps))->toBeLessThanOrEqual(5);
    }
});
