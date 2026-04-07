<?php

/**
 * @property \App\Models\User $user
 * @property \App\Services\UserAnalyticsService $service
 */

use App\Enums\CollaborationPermission;
use App\Enums\FocusSessionType;
use App\Enums\TaskComplexity;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Collaboration;
use App\Models\FocusSession;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\UserAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->service = app(UserAnalyticsService::class);
});

test('overview aggregates completions creations focus totals by day and project', function (): void {
    $start = CarbonImmutable::parse('2025-01-10', config('app.timezone'));
    $end = CarbonImmutable::parse('2025-01-16', config('app.timezone'));

    $project = Project::factory()->for($this->user)->create();

    Task::factory()->for($this->user)->for($project)->create([
        'title' => 'Done A',
        'status' => TaskStatus::Done,
        'completed_at' => Carbon::parse('2025-01-12 14:00:00', config('app.timezone')),
        'created_at' => Carbon::parse('2025-01-05 10:00:00', config('app.timezone')),
    ]);

    Task::factory()->for($this->user)->create([
        'title' => 'Done B no project',
        'status' => TaskStatus::Done,
        'project_id' => null,
        'completed_at' => Carbon::parse('2025-01-14 09:00:00', config('app.timezone')),
        'created_at' => Carbon::parse('2025-01-13 08:00:00', config('app.timezone')),
    ]);

    Task::factory()->for($this->user)->create([
        'title' => 'New incomplete',
        'status' => TaskStatus::ToDo,
        'completed_at' => null,
        'created_at' => Carbon::parse('2025-01-11 12:00:00', config('app.timezone')),
    ]);

    Task::factory()->for($this->user)->create([
        'title' => 'Outside range',
        'status' => TaskStatus::Done,
        'completed_at' => Carbon::parse('2025-01-20 12:00:00', config('app.timezone')),
        'created_at' => Carbon::parse('2025-01-18 12:00:00', config('app.timezone')),
    ]);

    $taskForFocus = Task::factory()->for($this->user)->create();

    FocusSession::factory()->for($this->user)->for($taskForFocus, 'focusable')->work()->completed()->create([
        'duration_seconds' => 60,
        'started_at' => Carbon::parse('2025-01-12 10:00:00', config('app.timezone')),
        'ended_at' => Carbon::parse('2025-01-12 10:01:00', config('app.timezone')),
    ]);

    FocusSession::factory()->for($this->user)->for($taskForFocus, 'focusable')->work()->completed()->create([
        'duration_seconds' => 120,
        'started_at' => Carbon::parse('2025-01-15 16:00:00', config('app.timezone')),
        'ended_at' => Carbon::parse('2025-01-15 16:02:00', config('app.timezone')),
    ]);

    FocusSession::factory()->for($this->user)->for($taskForFocus, 'focusable')->create([
        'type' => FocusSessionType::ShortBreak,
        'completed' => true,
        'duration_seconds' => 300,
        'started_at' => Carbon::parse('2025-01-12 11:00:00', config('app.timezone')),
        'ended_at' => Carbon::parse('2025-01-12 11:05:00', config('app.timezone')),
    ]);

    $overview = $this->service->overview($this->user, $start, $end);

    expect($overview->tasksCompletedCount)->toBe(2)
        ->and($overview->tasksCreatedCount)->toBe(2)
        ->and($overview->focusWorkSecondsTotal)->toBe(180)
        ->and($overview->focusWorkSessionsCount)->toBe(2)
        ->and($overview->tasksCompletedByDay)->toBe([
            '2025-01-12' => 1,
            '2025-01-14' => 1,
        ])
        ->and($overview->focusWorkSecondsByDay)->toBe([
            '2025-01-12' => 60,
            '2025-01-15' => 120,
        ])
        ->and($overview->tasksCompletedByProjectId)->toHaveKey('none')
        ->and($overview->tasksCompletedByProjectId)->toHaveKey((string) $project->id)
        ->and($overview->tasksCompletedByProjectId['none'])->toBe(1)
        ->and($overview->tasksCompletedByProjectId[(string) $project->id])->toBe(1);
});

test('overview includes tasks completed in range that are later soft deleted', function (): void {
    $start = CarbonImmutable::parse('2025-02-01', config('app.timezone'));
    $end = CarbonImmutable::parse('2025-02-28', config('app.timezone'));

    $task = Task::factory()->for($this->user)->create([
        'completed_at' => Carbon::parse('2025-02-10 12:00:00', config('app.timezone')),
        'created_at' => Carbon::parse('2025-01-01 12:00:00', config('app.timezone')),
    ]);

    $task->delete();

    $overview = $this->service->overview($this->user, $start, $end);

    expect($overview->tasksCompletedCount)->toBe(1)
        ->and($overview->tasksCreatedCount)->toBe(0);
});

test('overview excludes tasks created in range when they are soft deleted', function (): void {
    $start = CarbonImmutable::parse('2025-03-01', config('app.timezone'));
    $end = CarbonImmutable::parse('2025-03-31', config('app.timezone'));

    $task = Task::factory()->for($this->user)->create([
        'completed_at' => null,
        'created_at' => Carbon::parse('2025-03-05 12:00:00', config('app.timezone')),
    ]);

    $task->delete();

    $overview = $this->service->overview($this->user, $start, $end);

    expect($overview->tasksCreatedCount)->toBe(0)
        ->and($overview->tasksCompletedCount)->toBe(0);
});

test('collaborator sees shared task completions in their overview', function (): void {
    $owner = User::factory()->create();
    $collaborator = User::factory()->create();

    $start = CarbonImmutable::parse('2025-04-01', config('app.timezone'));
    $end = CarbonImmutable::parse('2025-04-30', config('app.timezone'));

    $task = Task::factory()->for($owner)->create([
        'completed_at' => Carbon::parse('2025-04-15 14:00:00', config('app.timezone')),
    ]);

    Collaboration::query()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $collaborator->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    $overview = app(UserAnalyticsService::class)->overview($collaborator, $start, $end);

    expect($overview->tasksCompletedCount)->toBe(1)
        ->and($overview->tasksCompletedByDay)->toBe(['2025-04-15' => 1])
        ->and($overview->tasksCompletedByProjectId['none'])->toBe(1);
});

test('overview throws when start is after end', function (): void {
    expect(fn () => $this->service->overview(
        $this->user,
        CarbonImmutable::parse('2025-05-10', config('app.timezone')),
        CarbonImmutable::parse('2025-05-01', config('app.timezone')),
    ))->toThrow(InvalidArgumentException::class, 'Analytics period start must be on or before the period end.');
});

test('completed tasks bucket by app timezone local calendar day', function (): void {
    config(['app.timezone' => 'Asia/Tokyo']);

    $start = CarbonImmutable::parse('2025-06-01', 'Asia/Tokyo');
    $end = CarbonImmutable::parse('2025-06-30', 'Asia/Tokyo');

    Task::factory()->for($this->user)->create([
        'completed_at' => Carbon::parse('2025-06-02 01:00:00', 'Asia/Tokyo'),
    ]);

    $overview = $this->service->overview($this->user, $start, $end);

    expect($overview->tasksCompletedByDay)->toBe(['2025-06-02' => 1]);
});

test('normalizes period to start and end of day in app timezone', function (): void {
    $tz = config('app.timezone');
    $start = CarbonImmutable::parse('2025-07-10 14:22:00', $tz);
    $end = CarbonImmutable::parse('2025-07-12 09:05:00', $tz);

    $overview = $this->service->overview($this->user, $start, $end);

    expect($overview->periodStart->equalTo(CarbonImmutable::parse('2025-07-10', $tz)->startOfDay()))->toBeTrue()
        ->and($overview->periodEnd->equalTo(CarbonImmutable::parse('2025-07-12', $tz)->endOfDay()))->toBeTrue();
});

test('dashboard overview returns preset period with previous comparison and chart-ready trends', function (): void {
    Carbon::setTestNow(Carbon::parse('2025-01-31 10:00:00', config('app.timezone')));

    Task::factory()->for($this->user)->create([
        'status' => TaskStatus::Done,
        'priority' => TaskPriority::High,
        'complexity' => TaskComplexity::Complex,
        'completed_at' => Carbon::parse('2025-01-30 14:00:00', config('app.timezone')),
        'created_at' => Carbon::parse('2025-01-28 09:00:00', config('app.timezone')),
    ]);

    Task::factory()->for($this->user)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Low,
        'complexity' => TaskComplexity::Simple,
        'completed_at' => null,
        'created_at' => Carbon::parse('2025-01-30 09:00:00', config('app.timezone')),
        'end_datetime' => Carbon::parse('2025-01-29 09:00:00', config('app.timezone')),
    ]);

    Task::factory()->for($this->user)->create([
        'status' => TaskStatus::Doing,
        'priority' => TaskPriority::Medium,
        'complexity' => TaskComplexity::Moderate,
        'completed_at' => null,
        'created_at' => Carbon::parse('2025-01-31 11:00:00', config('app.timezone')),
        'end_datetime' => Carbon::parse('2025-02-03 12:00:00', config('app.timezone')),
    ]);

    Task::factory()->for($this->user)->create([
        'status' => TaskStatus::Done,
        'priority' => TaskPriority::Urgent,
        'complexity' => TaskComplexity::Moderate,
        'completed_at' => Carbon::parse('2025-01-25 13:00:00', config('app.timezone')),
        'created_at' => Carbon::parse('2025-01-24 08:00:00', config('app.timezone')),
    ]);

    $focusTask = Task::factory()->for($this->user)->create();
    FocusSession::factory()->for($this->user)->for($focusTask, 'focusable')->work()->completed()->create([
        'duration_seconds' => 600,
        'started_at' => Carbon::parse('2025-01-30 08:00:00', config('app.timezone')),
        'ended_at' => Carbon::parse('2025-01-30 08:10:00', config('app.timezone')),
    ]);
    FocusSession::factory()->for($this->user)->for($focusTask, 'focusable')->work()->completed()->create([
        'duration_seconds' => 300,
        'started_at' => Carbon::parse('2025-01-24 08:00:00', config('app.timezone')),
        'ended_at' => Carbon::parse('2025-01-24 08:05:00', config('app.timezone')),
    ]);

    $overview = $this->service->dashboardOverview($this->user, '7d');

    expect($overview->periodStart->toDateString())->toBe('2025-01-25')
        ->and($overview->periodEnd->toDateString())->toBe('2025-01-31')
        ->and($overview->previousPeriodStart->toDateString())->toBe('2025-01-18')
        ->and($overview->previousPeriodEnd->toDateString())->toBe('2025-01-24')
        ->and($overview->cards['tasks_created']['current'])->toBe(4)
        ->and($overview->cards['tasks_created']['previous'])->toBe(1)
        ->and($overview->cards['tasks_created']['delta'])->toBe(3)
        ->and($overview->cards['tasks_completed']['current'])->toBe(2)
        ->and($overview->cards['tasks_completed']['previous'])->toBe(0)
        ->and($overview->cards['completion_rate']['current'])->toBe(50.0)
        ->and($overview->cards['completion_rate']['previous'])->toBe(0.0)
        ->and($overview->cards['overdue']['current'])->toBe(1)
        ->and($overview->cards['due_soon']['current'])->toBe(1)
        ->and($overview->cards['focus_work_seconds']['current'])->toBe(600)
        ->and($overview->cards['focus_work_seconds']['previous'])->toBe(300)
        ->and($overview->cards['focus_sessions']['current'])->toBe(1)
        ->and($overview->cards['focus_sessions']['previous'])->toBe(1)
        ->and($overview->trends['labels'])->toHaveCount(7)
        ->and($overview->trends['tasks_created'])->toHaveCount(7)
        ->and($overview->trends['tasks_completed'])->toHaveCount(7)
        ->and($overview->trends['focus_work_seconds'])->toHaveCount(7)
        ->and($overview->trends['focus_sessions'])->toHaveCount(7);

    Carbon::setTestNow();
});

test('dashboard overview project breakdown resolves names and fallback labels', function (): void {
    Carbon::setTestNow(Carbon::parse('2025-06-15 10:00:00', config('app.timezone')));

    $project = Project::factory()->for($this->user)->create(['name' => 'Semester Project']);

    Task::factory()->for($this->user)->for($project)->create([
        'status' => TaskStatus::Done,
        'completed_at' => Carbon::parse('2025-06-14 09:00:00', config('app.timezone')),
        'created_at' => Carbon::parse('2025-06-12 09:00:00', config('app.timezone')),
    ]);

    Task::factory()->for($this->user)->create([
        'status' => TaskStatus::Done,
        'project_id' => null,
        'completed_at' => Carbon::parse('2025-06-13 09:00:00', config('app.timezone')),
        'created_at' => Carbon::parse('2025-06-10 09:00:00', config('app.timezone')),
    ]);

    $overview = $this->service->dashboardOverview($this->user, '30d');
    $projectBreakdown = collect($overview->breakdowns['project'])->keyBy('key');

    expect($projectBreakdown[(string) $project->id]['label'])->toBe('Semester Project')
        ->and($projectBreakdown[(string) $project->id]['value'])->toBe(1)
        ->and($projectBreakdown['none']['label'])->toBe('No Project')
        ->and($projectBreakdown['none']['value'])->toBe(1);

    Carbon::setTestNow();
});

test('dashboard overview supports daily weekly and monthly preset aliases', function (): void {
    Carbon::setTestNow(Carbon::parse('2025-08-20 10:00:00', config('app.timezone')));

    $dailyOverview = $this->service->dashboardOverview($this->user, 'daily');
    $weeklyOverview = $this->service->dashboardOverview($this->user, 'weekly');
    $monthlyOverview = $this->service->dashboardOverview($this->user, 'monthly');

    expect($dailyOverview->preset)->toBe('daily')
        ->and($dailyOverview->trends['labels'])->toHaveCount(7)
        ->and($weeklyOverview->preset)->toBe('weekly')
        ->and($weeklyOverview->trends['labels'])->toHaveCount(30)
        ->and($monthlyOverview->preset)->toBe('monthly')
        ->and($monthlyOverview->trends['labels'])->toHaveCount(90);

    Carbon::setTestNow();
});
