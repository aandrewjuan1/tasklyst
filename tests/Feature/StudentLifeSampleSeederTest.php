<?php

use App\Enums\TaskSourceType;
use App\Models\Event;
use App\Models\RecurringTask;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\StudentLifeSampleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-12 14:00:00', config('app.timezone')));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('seeds brightspace tasks chores extra tasks and events for the demo user', function (): void {
    $user = User::factory()->create([
        'email' => 'andrew.juan.cvt@eac.edu.ph',
    ]);

    (new StudentLifeSampleSeeder)->run();

    $anchoredDueFloor = Carbon::now()
        ->startOfDay()
        ->addDays(StudentLifeSampleSeeder::MIN_OPEN_SCHEDULE_LEAD_DAYS);

    $recurringChoreTitles = [
        'Wash dishes after dinner',
        'Walk 10k steps',
        'Review today’s lecture notes',
        'Practice drawing for 20 minutes',
        'Prepare tomorrow’s school bag',
    ];

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
        expect($task->source_url)->toBe(StudentLifeSampleSeeder::BRIGHTSPACE_PLACEHOLDER_SOURCE_URL);
    });

    $recurringChoreTasks = Task::query()
        ->where('user_id', $user->id)
        ->where('source_type', TaskSourceType::Manual)
        ->whereIn('title', $recurringChoreTitles)
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
        ->where('title', '!=', StudentLifeSampleSeeder::INTENTIONAL_OVERDUE_STRESS_TASK_TITLE)
        ->pluck('end_datetime')
        ->map(fn ($dt) => Carbon::parse($dt));

    expect($pendingDueDates)->not->toBeEmpty();
    expect($pendingDueDates->every(fn (Carbon $dt) => $dt->greaterThanOrEqualTo($anchoredDueFloor)))->toBeTrue();

    $uniqueDueDates = $pendingDueDates
        ->map(fn (Carbon $dt) => $dt->toDateString())
        ->unique()
        ->values();

    expect($uniqueDueDates->count())->toBeGreaterThan(1);

    expect(Reminder::query()->where('user_id', $user->id)->count())->toBeGreaterThan(0);

    $user->refresh();

    expect($user->notifications()->count())->toBeGreaterThan(0);

    $notificationTypes = $user->notifications->map(fn ($n) => data_get($n->data, 'type'))->filter()->values();

    expect($notificationTypes)->toContain('task_overdue');
    expect($notificationTypes)->toContain('task_due_soon');
    expect($notificationTypes)->toContain('event_start_soon');
});
