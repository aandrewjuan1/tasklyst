<?php

use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\WorkspaceListAggregator;

test('dedupes the same task when it appears in overdue and day collections', function (): void {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create([
        'title' => 'DupTask',
        'start_datetime' => now()->subDays(2)->startOfDay(),
        'end_datetime' => now()->subDay(),
    ]);

    $overdue = collect([['kind' => 'task', 'item' => $task]]);
    $projects = collect();
    $events = collect();
    $tasks = collect([$task]);

    $result = WorkspaceListAggregator::mergeOrderAndDedupe($overdue, $projects, $events, $tasks);

    expect($result)->toHaveCount(1)
        ->and($result->first()['isOverdue'])->toBeTrue();
});

test('orders day items by start time ascending', function (): void {
    $user = User::factory()->create();
    $later = Task::factory()->for($user)->create([
        'title' => 'Later',
        'start_datetime' => now()->startOfDay()->addHours(14),
        'end_datetime' => null,
    ]);
    $earlier = Task::factory()->for($user)->create([
        'title' => 'Earlier',
        'start_datetime' => now()->startOfDay()->addHours(9),
        'end_datetime' => null,
    ]);

    $overdue = collect();
    $projects = collect();
    $events = collect();
    $tasks = collect([$later, $earlier]);

    $result = WorkspaceListAggregator::mergeOrderAndDedupe($overdue, $projects, $events, $tasks);

    expect($result->pluck('item.title')->all())->toBe(['Earlier', 'Later']);
});

test('places overdue strip before day items', function (): void {
    $user = User::factory()->create();
    $overdueTask = Task::factory()->for($user)->create([
        'title' => 'OverdueOne',
        'start_datetime' => now()->subDays(5),
        'end_datetime' => now()->subDays(3),
    ]);
    $dayTask = Task::factory()->for($user)->create([
        'title' => 'DayOne',
        'start_datetime' => now()->startOfDay()->addHour(),
        'end_datetime' => null,
    ]);

    $overdue = collect([['kind' => 'task', 'item' => $overdueTask]]);
    $projects = collect();
    $events = collect();
    $tasks = collect([$dayTask]);

    $result = WorkspaceListAggregator::mergeOrderAndDedupe($overdue, $projects, $events, $tasks);

    expect($result->pluck('item.title')->all())->toBe(['OverdueOne', 'DayOne']);
});

test('sorts projects and events in day strip by start or end datetime', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create([
        'name' => 'P',
        'start_datetime' => now()->startOfDay()->addHours(12),
        'end_datetime' => null,
    ]);
    $event = Event::factory()->for($user)->create([
        'title' => 'E',
        'start_datetime' => now()->startOfDay()->addHours(8),
        'end_datetime' => now()->startOfDay()->addHours(9),
        'status' => \App\Enums\EventStatus::Scheduled,
    ]);

    $overdue = collect();
    $projects = collect([$project]);
    $events = collect([$event]);
    $tasks = collect();

    $result = WorkspaceListAggregator::mergeOrderAndDedupe($overdue, $projects, $events, $tasks);

    expect($result->pluck('kind')->all())->toBe(['event', 'project']);
});
