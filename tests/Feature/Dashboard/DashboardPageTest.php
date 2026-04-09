<?php

use App\Enums\ActivityLogAction;
use App\Enums\CollaborationPermission;
use App\Enums\TaskPriority;
use App\Enums\TaskSourceType;
use App\Enums\TaskStatus;
use App\Models\ActivityLog;
use App\Models\CalendarFeed;
use App\Models\Collaboration;
use App\Models\CollaborationInvitation;
use App\Models\Event;
use App\Models\FocusSession;
use App\Models\LlmToolCall;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('dashboard loads for authenticated user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertStatus(200);
});

test('dashboard hero greets user by first name', function () {
    $user = User::factory()->create(['name' => 'Jordan Smith']);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertSuccessful();
    $response->assertSee('Dashboard — Hello, Jordan!', false);
});

test('dashboard summary shows total incomplete tasks count', function () {
    $user = User::factory()->create();

    foreach (range(1, 3) as $_) {
        Task::factory()->for($user)->create(['completed_at' => null]);
    }
    Task::factory()->for($user)->create(['completed_at' => now()]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertSuccessful();
    $response->assertSee(__('Total tasks'), false);

    expect(preg_match('/data-testid="dashboard-summary-total-tasks-value"[^>]*>\s*(\d+)\s*</', $response->getContent(), $matches))->toBe(1);
    expect($matches[1])->toBe('3');
});

test('dashboard summary shows to-do tasks count', function () {
    $user = User::factory()->create();

    Task::factory()->for($user)->count(2)->create([
        'status' => TaskStatus::ToDo,
        'completed_at' => null,
    ]);
    Task::factory()->for($user)->create([
        'status' => TaskStatus::Doing,
        'completed_at' => null,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertSuccessful();
    $response->assertSee(__('To-Do Tasks'), false);

    expect(preg_match('/data-testid="dashboard-summary-todo-tasks-value"[^>]*>\s*(\d+)\s*</', $response->getContent(), $matches))->toBe(1);
    expect($matches[1])->toBe('2');
});

test('dashboard urgent now shows at most three rows and see all link when more exist', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    foreach (range(1, 4) as $i) {
        Task::factory()->for($user)->for($project)->create([
            'title' => "Urgent Task {$i}",
            'priority' => TaskPriority::Urgent,
            'status' => TaskStatus::ToDo,
            'end_datetime' => now()->addHours($i),
            'completed_at' => null,
        ]);
    }

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertSuccessful();
    expect(substr_count((string) $response->getContent(), 'data-testid="dashboard-row-urgent-item"'))->toBe(3);
    $response->assertSee('data-testid="dashboard-urgent-now-see-all"', false);
    $response->assertSee(__('See all in Workspace'), false);
});

test('dashboard urgent now omits see all when at most three items', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    foreach (range(1, 3) as $i) {
        Task::factory()->for($user)->for($project)->create([
            'title' => "Urgent Task {$i}",
            'priority' => TaskPriority::Urgent,
            'status' => TaskStatus::ToDo,
            'end_datetime' => now()->addHours($i),
            'completed_at' => null,
        ]);
    }

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertSuccessful();
    expect(substr_count((string) $response->getContent(), 'data-testid="dashboard-row-urgent-item"'))->toBe(3);
    $response->assertDontSee('data-testid="dashboard-urgent-now-see-all"', false);
});

test('dashboard phase 1 sections render with seeded data', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create([
        'name' => 'Phase 1 Project',
        'end_datetime' => now()->addDays(2),
    ]);

    Task::factory()->for($user)->for($project)->create([
        'title' => 'Urgent Dashboard Task',
        'priority' => TaskPriority::Urgent,
        'status' => TaskStatus::ToDo,
        'end_datetime' => now()->addHours(4),
        'completed_at' => null,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertSuccessful();
    $response->assertSee('Urgent Now', false);
    $response->assertSee('Project Health', false);
    $response->assertSee('Urgent Dashboard Task', false);
    $response->assertSee('Phase 1 Project', false);
});

test('dashboard phase 2 sections render with collaboration pulse and calendar feed health data', function () {
    $user = User::factory()->create();
    $inviter = User::factory()->create();

    $task = Task::factory()->for($user)->create([
        'title' => 'Collaboration Target Task',
        'completed_at' => null,
    ]);

    CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $inviter->id,
        'invitee_email' => $user->email,
        'invitee_user_id' => $user->id,
        'permission' => CollaborationPermission::Edit,
        'status' => 'pending',
    ]);

    Collaboration::query()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $user->id,
        'permission' => CollaborationPermission::View,
    ]);

    ActivityLog::query()->create([
        'loggable_type' => Task::class,
        'loggable_id' => $task->id,
        'user_id' => $inviter->id,
        'action' => ActivityLogAction::CollaboratorInvited,
        'payload' => ['note' => 'invited'],
    ]);

    $feed = CalendarFeed::query()->create([
        'user_id' => $user->id,
        'name' => 'Brightspace Feed',
        'feed_url' => 'https://example.com/feed.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
        'last_synced_at' => now()->subMinutes(30),
    ]);

    Task::factory()->for($user)->create([
        'title' => 'Imported from feed',
        'calendar_feed_id' => $feed->id,
        'source_type' => TaskSourceType::Brightspace,
        'source_id' => 'brightspace-item-1',
        'completed_at' => null,
        'updated_at' => now()->subHours(2),
        'created_at' => now()->subHours(5),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertSuccessful();
    $response->assertSee('Collaboration Pulse', false);
    $response->assertSee('BRIGHTSPACE CALENDAR FEED', false);
    $response->assertSee('Sync Brightspace Calendar', false);
    $response->assertSee('Collaboration Target Task', false);

    expect(preg_match('/data-testid="dashboard-collab-pending-invites"[^>]*>\s*(\d+)\s*</', $response->getContent(), $matches))->toBe(1);
    expect($matches[1])->toBe('1');
});

test('dashboard rich sections render focus, calendar load, no-date backlog, and llm activity', function () {
    $user = User::factory()->create();

    Task::factory()->for($user)->create([
        'title' => 'No Date Backlog Task',
        'status' => TaskStatus::ToDo,
        'start_datetime' => null,
        'end_datetime' => null,
        'completed_at' => null,
    ]);

    FocusSession::query()->create([
        'user_id' => $user->id,
        'focusable_type' => Task::class,
        'focusable_id' => null,
        'type' => 'work',
        'focus_mode_type' => 'sprint',
        'sequence_number' => 1,
        'duration_seconds' => 1500,
        'completed' => true,
        'started_at' => now()->subHours(2),
        'ended_at' => now()->subHours(1)->subMinutes(35),
        'paused_seconds' => 0,
        'payload' => [],
    ]);

    Event::factory()->for($user)->create([
        'title' => 'Conflict Event A',
        'start_datetime' => now()->addHours(2),
        'end_datetime' => now()->addHours(3),
        'status' => 'scheduled',
    ]);

    Event::factory()->for($user)->create([
        'title' => 'Conflict Event B',
        'start_datetime' => now()->addHours(2)->addMinutes(30),
        'end_datetime' => now()->addHours(4),
        'status' => 'scheduled',
    ]);

    $thread = TaskAssistantThread::query()->create([
        'user_id' => $user->id,
        'title' => 'Planner thread',
        'metadata' => [],
    ]);

    LlmToolCall::query()->create([
        'thread_id' => $thread->id,
        'message_id' => null,
        'tool_name' => 'update_task',
        'params_json' => ['taskId' => 1],
        'result_json' => ['ok' => true],
        'status' => 'success',
        'operation_token' => null,
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertSuccessful();
    $response->assertSee('No-date Backlog', false);
    $response->assertSee('Focus + Throughput', false);
    $response->assertSee('Calendar Load (24h)', false);
    $response->assertSee('LLM Assistant Activity', false);
    $response->assertSee('Quick actions', false);
    $response->assertSee('No Date Backlog Task', false);

    expect(preg_match('/data-testid="dashboard-no-date-backlog-count"[^>]*>\s*(\d+)\s*</', $response->getContent(), $matches))->toBe(1);
    expect($matches[1])->toBe('1');
});

test('dashboard calendar renders selected-day agenda and summary counts', function () {
    $user = User::factory()->create();
    $selectedDate = now()->toDateString();

    Task::factory()->for($user)->create([
        'title' => 'Agenda Urgent Task',
        'priority' => TaskPriority::Urgent,
        'status' => TaskStatus::ToDo,
        'end_datetime' => now()->startOfDay()->addHours(15),
        'completed_at' => null,
    ]);

    Event::factory()->for($user)->create([
        'title' => 'Agenda Timed Event',
        'status' => 'scheduled',
        'start_datetime' => now()->startOfDay()->addHours(14),
        'end_datetime' => now()->startOfDay()->addHours(16),
        'all_day' => false,
    ]);

    Event::factory()->for($user)->create([
        'title' => 'Agenda Conflicting Event',
        'status' => 'scheduled',
        'start_datetime' => now()->startOfDay()->addHours(15),
        'end_datetime' => now()->startOfDay()->addHours(17),
        'all_day' => false,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard', ['date' => $selectedDate]));

    $response->assertSuccessful();
    $response->assertSee('data-testid="calendar-selected-day-agenda"', false);
    $response->assertSee('Agenda Urgent Task', false);
    $response->assertSee('Agenda Timed Event', false);

    expect(preg_match('/data-testid="calendar-agenda-summary-tasks"[^>]*>\s*(\d+)\s*</', $response->getContent(), $taskMatches))->toBe(1);
    expect((int) $taskMatches[1])->toBeGreaterThanOrEqual(1);

    expect(preg_match('/data-testid="calendar-agenda-summary-events"[^>]*>\s*(\d+)\s*</', $response->getContent(), $eventMatches))->toBe(1);
    expect((int) $eventMatches[1])->toBe(2);

    expect(preg_match('/data-testid="calendar-agenda-summary-conflicts"[^>]*>\s*(\d+)\s*</', $response->getContent(), $conflictMatches))->toBe(1);
    expect((int) $conflictMatches[1])->toBe(1);
});

test('dashboard calendar source filter imported limits agenda to imported tasks', function () {
    $user = User::factory()->create();
    $selectedDate = now()->toDateString();
    $feed = CalendarFeed::query()->create([
        'user_id' => $user->id,
        'name' => 'Imported Feed',
        'feed_url' => 'https://example.com/calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
    ]);

    Task::factory()->for($user)->create([
        'title' => 'Manual Agenda Task',
        'priority' => TaskPriority::High,
        'status' => TaskStatus::ToDo,
        'source_type' => TaskSourceType::Manual->value,
        'end_datetime' => now()->startOfDay()->addHours(13),
        'completed_at' => null,
    ]);

    Task::factory()->for($user)->create([
        'title' => 'Imported Agenda Task',
        'priority' => TaskPriority::High,
        'status' => TaskStatus::ToDo,
        'source_type' => TaskSourceType::Brightspace->value,
        'source_id' => 'imported-agenda-task-1',
        'calendar_feed_id' => $feed->id,
        'end_datetime' => now()->startOfDay()->addHours(14),
        'completed_at' => null,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard', [
        'date' => $selectedDate,
        'calendar_source' => 'imported',
    ]));

    $response->assertSuccessful();
    $response->assertSee('data-testid="calendar-source-filter-imported"', false);
    $response->assertSee('Imported Agenda Task', false);

    expect(preg_match('/data-testid="calendar-agenda-summary-tasks"[^>]*>\s*(\d+)\s*</', $response->getContent(), $summaryMatches))->toBe(1);
    expect((int) $summaryMatches[1])->toBe(1);
});
