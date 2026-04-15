<?php

use App\Enums\ReminderStatus;
use App\Enums\ReminderType;
use App\Enums\TaskSourceType;
use App\Enums\TaskStatus;
use App\Models\Event;
use App\Models\EventException;
use App\Models\EventInstance;
use App\Models\RecurringEvent;
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
    expect(
        Task::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $recurringChoreTasks->pluck('id'))
            ->whereHas('recurringTask.taskInstances')
            ->count()
    )->toBe(5);
    expect(
        Task::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $recurringChoreTasks->pluck('id'))
            ->whereHas('recurringTask.taskInstances', fn ($query) => $query->whereDate('instance_date', Carbon::today()))
            ->exists()
    )->toBeTrue();
    expect(
        Task::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $recurringChoreTasks->pluck('id'))
            ->whereHas('recurringTask.taskInstances', fn ($query) => $query
                ->whereDate('instance_date', Carbon::today())
                ->where('status', TaskStatus::Doing))
            ->exists()
    )->toBeTrue();

    $manualTasks = Task::query()
        ->where('user_id', $user->id)
        ->where('source_type', TaskSourceType::Manual)
        ->whereNotIn('id', $recurringChoreTasks->pluck('id'))
        ->get();

    expect($manualTasks->count())->toBeGreaterThanOrEqual(5);
    expect(
        Task::query()
            ->where('user_id', $user->id)
            ->where('title', 'Workspace visibility anchor: active doing task')
            ->where('status', TaskStatus::Doing)
            ->exists()
    )->toBeTrue();
    expect(
        Task::query()
            ->where('user_id', $user->id)
            ->where('title', 'Dashboard anchor: due today task')
            ->exists()
    )->toBeTrue();

    $events = Event::query()
        ->where('user_id', $user->id)
        ->get();

    expect($events->count())->toBeGreaterThanOrEqual(3);
    expect(
        Event::query()
            ->where('user_id', $user->id)
            ->where('title', 'Dashboard anchor: today event')
            ->exists()
    )->toBeTrue();
    $recurringEvent = RecurringEvent::query()
        ->whereHas('event', fn ($query) => $query->where('user_id', $user->id))
        ->first();
    expect($recurringEvent)->not->toBeNull();
    expect(
        EventInstance::query()
            ->where('recurring_event_id', $recurringEvent?->id)
            ->count()
    )->toBeGreaterThanOrEqual(4);
    expect(
        EventInstance::query()
            ->where('recurring_event_id', $recurringEvent?->id)
            ->where('cancelled', true)
            ->exists()
    )->toBeTrue();
    expect(
        EventException::query()
            ->where('recurring_event_id', $recurringEvent?->id)
            ->exists()
    )->toBeTrue();

    $pendingDueDates = Task::query()
        ->where('user_id', $user->id)
        ->whereNull('completed_at')
        ->whereNotNull('end_datetime')
        ->whereDoesntHave('recurringTask')
        ->where('title', '!=', StudentLifeSampleSeeder::INTENTIONAL_OVERDUE_STRESS_TASK_TITLE)
        ->where('title', 'not like', 'Workspace visibility anchor:%')
        ->where('title', 'not like', 'Dashboard anchor:%')
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
    $allSeededReminderTypes = Reminder::query()
        ->where('user_id', $user->id)
        ->pluck('type')
        ->map(static fn ($type) => $type instanceof ReminderType ? $type->value : (string) $type)
        ->unique()
        ->values()
        ->all();
    expect($allSeededReminderTypes)->toContain(...array_map(
        static fn (ReminderType $type): string => $type->value,
        ReminderType::cases()
    ));

    $seededReminderStatuses = Reminder::query()
        ->where('user_id', $user->id)
        ->pluck('status')
        ->map(static fn ($status) => $status instanceof ReminderStatus ? $status->value : (string) $status)
        ->unique()
        ->values()
        ->all();
    expect($seededReminderStatuses)->toContain(
        ReminderStatus::Pending->value,
        ReminderStatus::Sent->value,
        ReminderStatus::Cancelled->value
    );

    expect(
        Reminder::query()
            ->where('user_id', $user->id)
            ->where('status', ReminderStatus::Pending)
            ->whereNotNull('snoozed_until')
            ->exists()
    )->toBeTrue();

    $user->refresh();

    expect($user->notifications()->count())->toBeGreaterThan(0);

    $notificationTypes = $user->notifications->map(fn ($n) => data_get($n->data, 'type'))->filter()->values();

    expect($notificationTypes)->toContain('task_overdue');
    expect($notificationTypes)->toContain('task_due_soon');
    expect($notificationTypes)->toContain('event_start_soon');
    expect($notificationTypes)->toContain('collaboration_invite_received');
    expect($notificationTypes)->toContain('daily_due_summary');
    expect($notificationTypes)->toContain('task_stalled');
    expect($notificationTypes)->toContain('project_deadline_risk');
    expect($notificationTypes)->toContain('recurrence_anomaly');
    expect($notificationTypes)->toContain('collaboration_invite_expiring');
    expect($notificationTypes)->toContain('calendar_feed_sync_failed');
    expect($notificationTypes)->toContain('calendar_feed_recovered');
    expect($notificationTypes)->toContain('calendar_feed_stale_sync');
    expect($notificationTypes)->toContain('focus_session_completed');
    expect($notificationTypes)->toContain('focus_drift_weekly');
    expect($notificationTypes)->toContain('assistant_action_required');
    expect($notificationTypes)->toContain('assistant_tool_call_failed');
    expect($notificationTypes)->toContain('collaborator_activity');
    expect($notificationTypes)->toContain('collaboration_invite_accepted_for_owner');

    expect($user->unreadNotifications()->exists())->toBeTrue();
    expect($user->readNotifications()->exists())->toBeTrue();
});
