<?php

use App\Enums\TaskSourceType;
use App\Models\Event;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\StudentLifeSampleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('seeds brightspace tasks chores extra tasks and events for the demo user', function (): void {
    $user = User::factory()->create([
        'email' => 'andrew.juan.cvt@eac.edu.ph',
    ]);

    (new StudentLifeSampleSeeder)->run();

    $dueDateFloor = Carbon::parse('2026-03-13')->startOfDay();

    $brightspaceTasks = Task::query()
        ->where('user_id', $user->id)
        ->where('source_type', TaskSourceType::Brightspace)
        ->get();

    expect($brightspaceTasks)->toHaveCount(20);

    $titles = $brightspaceTasks->pluck('title')->all();
    expect($titles)->toContain('ITCS 101 – Lab 3: Loops');
    expect($titles)->toContain('CS 220 – Lab 5: Linked Lists');

    $brightspaceTasks->each(function (Task $task): void {
        expect($task->source_type)->toBe(TaskSourceType::Brightspace);
        expect($task->calendar_feed_id)->toBeNull();
        expect($task->source_url)->toBeNull();
    });

    $recurringChoreTasks = Task::query()
        ->where('user_id', $user->id)
        ->where('source_type', TaskSourceType::Manual)
        ->whereIn('title', [
            'Wash dishes after dinner',
            'Walk 10k steps',
            'Review today’s lecture notes',
            'Practice drawing for 20 minutes',
            'Prepare tomorrow’s school bag',
        ])
        ->get();

    expect($recurringChoreTasks)->toHaveCount(5);

    $recurringDefinitions = RecurringTask::query()
        ->whereIn('task_id', $recurringChoreTasks->pluck('id'))
        ->get();

    expect($recurringDefinitions)->toHaveCount(5);

    $manualTasks = Task::query()
        ->where('user_id', $user->id)
        ->where('source_type', TaskSourceType::Manual)
        ->whereNotIn('id', $recurringChoreTasks->pluck('id'))
        ->get();

    expect($manualTasks->count())->toBeGreaterThanOrEqual(5);

    $events = Event::query()
        ->where('user_id', $user->id)
        ->get();

    expect($events->count())->toBeGreaterThanOrEqual(3);

    $pendingDueDates = Task::query()
        ->where('user_id', $user->id)
        ->whereNull('completed_at')
        ->whereNotNull('end_datetime')
        ->pluck('end_datetime')
        ->map(fn ($dt) => Carbon::parse($dt));

    expect($pendingDueDates)->not->toBeEmpty();
    expect($pendingDueDates->every(fn (Carbon $dt) => $dt->greaterThanOrEqualTo($dueDateFloor)))->toBeTrue();

    $uniqueDueDates = $pendingDueDates
        ->map(fn (Carbon $dt) => $dt->toDateString())
        ->unique()
        ->values();

    expect($uniqueDueDates->count())->toBeGreaterThan(1);
});
