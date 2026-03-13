<?php

use App\Enums\TaskPriority;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;

test('scope active for user returns only incomplete tasks for owner', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    Task::factory()->for($owner)->create([
        'title' => 'Completed',
        'completed_at' => now(),
        'priority' => TaskPriority::Urgent,
    ]);

    Task::factory()->for($other)->create([
        'title' => 'Other user task',
        'completed_at' => null,
        'priority' => TaskPriority::Urgent,
    ]);

    $high = Task::factory()->for($owner)->create([
        'title' => 'High',
        'completed_at' => null,
        'priority' => TaskPriority::High,
        'end_datetime' => Carbon::parse('2026-03-14 10:00:00'),
    ]);

    $urgent = Task::factory()->for($owner)->create([
        'title' => 'Urgent',
        'completed_at' => null,
        'priority' => TaskPriority::Urgent,
        'end_datetime' => Carbon::parse('2026-03-16 10:00:00'),
    ]);

    $tasks = Task::query()->activeForUser($owner->id)->get();

    expect($tasks->pluck('id')->values()->all())
        ->toBe([$urgent->id, $high->id]);
});

test('scope summary columns selects minimal task fields', function (): void {
    $user = User::factory()->create();
    Task::factory()->for($user)->create([
        'title' => 'Summary candidate',
        'description' => 'Detailed text',
        'duration' => 45,
    ]);

    $task = Task::query()->forUser($user->id)->summaryColumns()->firstOrFail();
    $attributes = $task->getAttributes();

    expect(array_keys($attributes))
        ->toContain('id', 'title', 'end_datetime', 'priority', 'duration')
        ->not->toContain('description');
});

test('scope for ids filters tasks to explicit ids only', function (): void {
    $user = User::factory()->create();
    $first = Task::factory()->for($user)->create();
    $second = Task::factory()->for($user)->create();
    Task::factory()->for($user)->create();

    $tasks = Task::query()->forIds([$first->id, $second->id])->get();

    expect($tasks->pluck('id')->sort()->values()->all())
        ->toBe([$first->id, $second->id]);
});
