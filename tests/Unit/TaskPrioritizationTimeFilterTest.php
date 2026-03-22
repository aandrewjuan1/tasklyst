<?php

use App\Services\LLM\Prioritization\TaskPrioritizationService;
use Carbon\CarbonImmutable;

test('filterTasksForTimeConstraint returns all tasks when constraint is none or null', function (): void {
    $service = app(TaskPrioritizationService::class);
    $now = CarbonImmutable::parse('2026-03-22 12:00:00', 'UTC');
    $tasks = [
        ['id' => 1, 'ends_at' => '2026-03-24T00:00:00+00:00'],
    ];

    expect($service->filterTasksForTimeConstraint($tasks, null, $now))->toBe($tasks);
    expect($service->filterTasksForTimeConstraint($tasks, 'none', $now))->toBe($tasks);
});

test('filterTasksForTimeConstraint keeps tasks due this calendar week', function (): void {
    $service = app(TaskPrioritizationService::class);
    $now = CarbonImmutable::parse('2026-03-22 12:00:00', 'UTC');
    $tasks = [
        ['id' => 1, 'ends_at' => '2026-03-24T00:00:00+00:00'],
        ['id' => 2, 'ends_at' => '2027-01-01T00:00:00+00:00'],
    ];

    $filtered = $service->filterTasksForTimeConstraint($tasks, 'this_week', $now);

    expect($filtered)->toHaveCount(1);
    expect($filtered[0]['id'])->toBe(1);
});
