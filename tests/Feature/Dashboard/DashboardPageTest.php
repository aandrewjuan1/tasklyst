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
use App\Models\Project;
use App\Models\RecurringTask;
use App\Models\SchoolClass;
use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('dashboard loads for authenticated user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertStatus(200);
});

test('dashboard at root shows a single notification bell in the layout', function () {
    $user = User::factory()->create();

    $html = (string) $this->actingAs($user)->get('/')->assertSuccessful()->getContent();

    expect(substr_count($html, 'data-test="notifications-bell-button"'))->toBe(1);
});

test('dashboard routes redirect guests to login', function () {
    $this->get('/')->assertRedirect(route('login'));
    $this->get('/dashboard')->assertRedirect(route('login'));
});

test('dashboard hero greets user by first name', function () {
    $user = User::factory()->create(['name' => 'Jordan Smith']);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertSuccessful();
    $response->assertSee('Dashboard — Hello, Jordan!', false);
});

test('dashboard hero keeps single primary focus header controls', function () {
    $user = User::factory()->create();

    $html = (string) $this->actingAs($user)->get(route('dashboard'))->getContent();

    expect($html)->toContain('Focus on what needs attention right now.');
    expect($html)->toContain('Ask AI assistant');
    expect($html)->toContain('data-test="notifications-bell-button"');
    expect(substr_count($html, 'data-test="notifications-bell-button"'))->toBe(1);
});

test('dashboard top kpi shows overdue tasks count', function () {
    $user = User::factory()->create();

    Task::factory()->for($user)->count(3)->create([
        'status' => TaskStatus::ToDo,
        'end_datetime' => now()->subHour(),
        'completed_at' => null,
    ]);
    Task::factory()->for($user)->create([
        'status' => TaskStatus::Done,
        'end_datetime' => now()->subHour(),
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertSuccessful();
    $response->assertSee(__('Overdue'), false);

    expect(preg_match('/data-testid="dashboard-kpi-overdue-value"[^>]*>\s*(\d+)\s*</', $response->getContent(), $matches))->toBe(1);
    expect($matches[1])->toBe('3');
});

test('dashboard overdue kpi reflects task deadline changes on a subsequent visit', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-09 10:00:00'));
    $user = User::factory()->create();

    $task = Task::factory()->for($user)->create([
        'status' => TaskStatus::ToDo,
        'end_datetime' => Carbon::parse('2026-04-10 12:00:00'),
        'completed_at' => null,
    ]);

    $before = $this->actingAs($user)->get(route('dashboard'));
    $before->assertSuccessful();
    expect(preg_match('/data-testid="dashboard-kpi-overdue-value"[^>]*>\s*(\d+)\s*</', $before->getContent(), $matches))->toBe(1);
    expect($matches[1])->toBe('0');

    $task->update(['end_datetime' => Carbon::parse('2026-04-08 12:00:00')]);

    $after = $this->actingAs($user)->get(route('dashboard'));
    $after->assertSuccessful();
    expect(preg_match('/data-testid="dashboard-kpi-overdue-value"[^>]*>\s*(\d+)\s*</', $after->getContent(), $matchesAfter))->toBe(1);
    expect($matchesAfter[1])->toBe('1');

    Carbon::setTestNow();
});

test('dashboard top kpi shows due on selected day count', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-09 09:00:00'));
    $user = User::factory()->create();
    $selectedDate = '2026-04-12';

    Task::factory()->for($user)->count(2)->create([
        'status' => TaskStatus::ToDo,
        'end_datetime' => Carbon::parse('2026-04-12 11:00:00'),
        'completed_at' => null,
    ]);
    Task::factory()->for($user)->create([
        'status' => TaskStatus::ToDo,
        'end_datetime' => Carbon::parse('2026-04-13 11:00:00'),
        'completed_at' => null,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard', ['date' => $selectedDate]));

    $response->assertSuccessful();
    $response->assertSee(__('Due on April 12'), false);

    expect(preg_match('/data-testid="dashboard-kpi-due_today-value"[^>]*>\s*(\d+)\s*</', $response->getContent(), $matches))->toBe(1);
    expect($matches[1])->toBe('2');

    Carbon::setTestNow();
});

test('dashboard doing tasks panel shows doing count', function () {
    $user = User::factory()->create();

    Task::factory()->for($user)->count(2)->create([
        'status' => TaskStatus::Doing,
        'completed_at' => null,
    ]);
    Task::factory()->for($user)->create([
        'status' => TaskStatus::ToDo,
        'completed_at' => null,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertSuccessful();
    $response->assertSee(__('Doing Tasks'), false);
    expect(preg_match('/data-testid="dashboard-doing-count"[^>]*>\s*(\d+)\s*</', $response->getContent(), $matches))->toBe(1);
    expect($matches[1])->toBe('2');
});

test('dashboard doing tasks includes recurring instance doing on selected day', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-09 08:00:00'));
    $user = User::factory()->create();
    $selectedDate = '2026-04-12';

    $recurringTask = Task::factory()->for($user)->create([
        'title' => 'Recurring Instance Doing Task',
        'status' => TaskStatus::ToDo,
        'end_datetime' => null,
        'completed_at' => null,
    ]);

    $recurring = RecurringTask::factory()->create([
        'task_id' => $recurringTask->id,
        'recurrence_type' => 'daily',
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-04-10 09:00:00'),
        'end_datetime' => null,
    ]);

    \App\Models\TaskInstance::query()->create([
        'task_id' => $recurringTask->id,
        'recurring_task_id' => $recurring->id,
        'instance_date' => Carbon::parse($selectedDate),
        'status' => TaskStatus::Doing,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard', ['date' => $selectedDate]));

    $response->assertSuccessful();
    $response->assertSee('Recurring Instance Doing Task', false);
    expect(preg_match('/data-testid="dashboard-doing-count"[^>]*>\s*(\d+)\s*</', $response->getContent(), $matches))->toBe(1);
    expect($matches[1])->toBe('1');

    Carbon::setTestNow();
});

test('dashboard top kpi shows total and completed tasks counts', function () {
    $user = User::factory()->create();

    Task::factory()->for($user)->count(2)->create([
        'status' => TaskStatus::ToDo,
        'completed_at' => null,
    ]);
    Task::factory()->for($user)->count(3)->create([
        'status' => TaskStatus::Done,
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertSuccessful();
    $response->assertSee(__('Total tasks'), false);
    $response->assertSee(__('Completed tasks'), false);

    expect(preg_match('/data-testid="dashboard-kpi-total-value"[^>]*>\s*(\d+)\s*</', $response->getContent(), $totalMatches))->toBe(1);
    expect($totalMatches[1])->toBe('5');

    expect(preg_match('/data-testid="dashboard-kpi-completed-value"[^>]*>\s*(\d+)\s*</', $response->getContent(), $completedMatches))->toBe(1);
    expect($completedMatches[1])->toBe('3');
});

test('dashboard today classes panel shows classes count for selected day', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-09 08:00:00'));
    $user = User::factory()->create();
    $selectedDate = '2026-04-12';

    SchoolClass::factory()->for($user)->create([
        'subject_name' => 'Algebra',
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
        'start_datetime' => Carbon::parse('2026-04-12 09:00:00'),
        'end_datetime' => Carbon::parse('2026-04-12 10:00:00'),
    ]);

    SchoolClass::factory()->for($user)->create([
        'subject_name' => 'Biology',
        'start_time' => '13:00:00',
        'end_time' => '14:00:00',
        'start_datetime' => Carbon::parse('2026-04-12 13:00:00'),
        'end_datetime' => Carbon::parse('2026-04-12 14:00:00'),
    ]);

    SchoolClass::factory()->for($user)->create([
        'subject_name' => 'Off Date Class',
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
        'start_datetime' => Carbon::parse('2026-04-13 09:00:00'),
        'end_datetime' => Carbon::parse('2026-04-13 10:00:00'),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard', ['date' => $selectedDate]));

    $response->assertSuccessful();
    $response->assertSee('data-testid="dashboard-section-today-classes-heading"', false);
    $response->assertSee(__('Classes on April 12'), false);
    expect(preg_match('/data-testid="dashboard-today-classes-count"[^>]*>\s*(\d+)\s*</', $response->getContent(), $matches))->toBe(1);
    expect($matches[1])->toBe('2');

    Carbon::setTestNow();
});

test('dashboard today classes panel renders rows with state badges and workspace deep links', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-12 10:00:00'));
    $user = User::factory()->create();
    $selectedDate = '2026-04-12';

    $currentClass = SchoolClass::factory()->for($user)->create([
        'subject_name' => 'Current Class',
        'start_time' => '09:00:00',
        'end_time' => '11:00:00',
        'start_datetime' => Carbon::parse('2026-04-12 09:00:00'),
        'end_datetime' => Carbon::parse('2026-04-12 11:00:00'),
    ]);

    SchoolClass::factory()->for($user)->create([
        'subject_name' => 'Next Class',
        'start_time' => '11:30:00',
        'end_time' => '12:30:00',
        'start_datetime' => Carbon::parse('2026-04-12 11:30:00'),
        'end_datetime' => Carbon::parse('2026-04-12 12:30:00'),
    ]);

    SchoolClass::factory()->for($user)->create([
        'subject_name' => 'Later Class',
        'start_time' => '15:00:00',
        'end_time' => '16:00:00',
        'start_datetime' => Carbon::parse('2026-04-12 15:00:00'),
        'end_datetime' => Carbon::parse('2026-04-12 16:00:00'),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard', ['date' => $selectedDate]));

    $response->assertSuccessful();
    $response->assertSee('data-testid="dashboard-section-today-classes-heading"', false);
    expect(substr_count((string) $response->getContent(), 'data-testid="dashboard-row-school-class"'))->toBe(3);
    $response->assertSee('Current Class', false);
    $response->assertSee('Next Class', false);
    $response->assertSee('Later Class', false);
    $response->assertSee('Now', false);
    $response->assertSee('Next', false);
    $response->assertSee('Later', false);
    $response->assertSee('school_class='.$currentClass->id, false);
    $response->assertSee('agenda_focus=1', false);

    Carbon::setTestNow();
});

test('dashboard today classes panel marks ended classes as past', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-12 20:00:00'));
    $user = User::factory()->create();
    $selectedDate = '2026-04-12';

    SchoolClass::factory()->for($user)->create([
        'subject_name' => 'Morning Class',
        'start_time' => '07:00:00',
        'end_time' => '10:00:00',
        'start_datetime' => Carbon::parse('2026-04-12 07:00:00'),
        'end_datetime' => Carbon::parse('2026-04-12 10:00:00'),
    ]);

    SchoolClass::factory()->for($user)->create([
        'subject_name' => 'Evening Class',
        'start_time' => '19:00:00',
        'end_time' => '21:00:00',
        'start_datetime' => Carbon::parse('2026-04-12 19:00:00'),
        'end_datetime' => Carbon::parse('2026-04-12 21:00:00'),
    ]);

    SchoolClass::factory()->for($user)->create([
        'subject_name' => 'Night Class',
        'start_time' => '22:00:00',
        'end_time' => '23:00:00',
        'start_datetime' => Carbon::parse('2026-04-12 22:00:00'),
        'end_datetime' => Carbon::parse('2026-04-12 23:00:00'),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard', ['date' => $selectedDate]));

    $response->assertSuccessful();
    $response->assertSee('Morning Class', false);
    $response->assertSee('Evening Class', false);
    $response->assertSee('Night Class', false);
    $response->assertSee('Past', false);
    $response->assertSee('Now', false);
    $response->assertSee('Next', false);
    $response->assertDontSee('Later', false);

    Carbon::setTestNow();
});

test('dashboard today classes panel shows see all and empty state appropriately', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-12 08:00:00'));
    $user = User::factory()->create();
    $selectedDate = '2026-04-12';

    foreach (range(1, 4) as $index) {
        SchoolClass::factory()->for($user)->create([
            'subject_name' => 'Class '.$index,
            'start_time' => sprintf('%02d:00:00', 8 + $index),
            'end_time' => sprintf('%02d:00:00', 9 + $index),
            'start_datetime' => Carbon::parse('2026-04-12 '.sprintf('%02d:00:00', 8 + $index)),
            'end_datetime' => Carbon::parse('2026-04-12 '.sprintf('%02d:00:00', 9 + $index)),
        ]);
    }

    $response = $this->actingAs($user)->get(route('dashboard', ['date' => $selectedDate]));
    $response->assertSuccessful();
    expect(substr_count((string) $response->getContent(), 'data-testid="dashboard-row-school-class"'))->toBe(3);
    $response->assertSee('data-testid="dashboard-today-classes-see-all"', false);

    $emptyUser = User::factory()->create();
    $emptyResponse = $this->actingAs($emptyUser)->get(route('dashboard', ['date' => $selectedDate]));
    $emptyResponse->assertSuccessful();
    $emptyResponse->assertSee('No classes for today.', false);

    Carbon::setTestNow();
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

test('dashboard urgent now includes only strict urgent tasks', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-09 09:00:00'));
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    Task::factory()->for($user)->for($project)->create([
        'title' => 'Critical Today Task',
        'priority' => TaskPriority::Low,
        'status' => TaskStatus::ToDo,
        'end_datetime' => Carbon::parse('2026-04-09 18:00:00'),
        'completed_at' => null,
    ]);

    Task::factory()->for($user)->for($project)->create([
        'title' => 'High Due Soon Task',
        'priority' => TaskPriority::High,
        'status' => TaskStatus::ToDo,
        'end_datetime' => Carbon::parse('2026-04-11 12:00:00'),
        'completed_at' => null,
    ]);

    Task::factory()->for($user)->for($project)->create([
        'title' => 'Urgent No Date Task',
        'priority' => TaskPriority::Urgent,
        'status' => TaskStatus::ToDo,
        'end_datetime' => null,
        'completed_at' => null,
    ]);

    Task::factory()->for($user)->for($project)->create([
        'title' => 'Medium No Date Task',
        'priority' => TaskPriority::Medium,
        'status' => TaskStatus::ToDo,
        'end_datetime' => null,
        'completed_at' => null,
    ]);

    Task::factory()->for($user)->for($project)->create([
        'title' => 'Medium Due Soon Task',
        'priority' => TaskPriority::Medium,
        'status' => TaskStatus::ToDo,
        'end_datetime' => Carbon::parse('2026-04-11 12:00:00'),
        'completed_at' => null,
    ]);

    Task::factory()->for($user)->for($project)->create([
        'title' => 'High Doing Without Due Date',
        'priority' => TaskPriority::High,
        'status' => TaskStatus::Doing,
        'end_datetime' => null,
        'completed_at' => null,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));
    $response->assertSuccessful();
    $html = (string) $response->getContent();
    preg_match_all('/<li class="px-4 py-3" data-testid="dashboard-row-urgent-item"[\s\S]*?<\/li>/', $html, $urgentRowsMatch);
    $urgentRowsHtml = implode("\n", $urgentRowsMatch[0] ?? []);

    expect(preg_match('/data-urgency-level="critical"[\s\S]*Critical Today Task/', $html))->toBe(1);
    expect(preg_match('/data-urgency-level="high"[\s\S]*High Due Soon Task/', $html))->toBe(1);
    expect(preg_match('/data-urgency-level="critical"[\s\S]*Urgent No Date Task/', $html))->toBe(1);
    expect($urgentRowsHtml)->not->toContain('Medium No Date Task');
    expect($urgentRowsHtml)->not->toContain('Medium Due Soon Task');
    expect($urgentRowsHtml)->not->toContain('High Doing Without Due Date');

    Carbon::setTestNow();
});

test('dashboard urgent now updates after task priority changes on a subsequent visit', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-09 09:00:00'));
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $task = Task::factory()->for($user)->for($project)->create([
        'title' => 'Workspace Reactive Urgent Title',
        'priority' => TaskPriority::Low,
        'status' => TaskStatus::ToDo,
        'end_datetime' => Carbon::parse('2026-04-11 12:00:00'),
        'completed_at' => null,
    ]);

    $before = $this->actingAs($user)->get(route('dashboard'));
    $before->assertSuccessful();
    $before->assertDontSee('Workspace Reactive Urgent Title', false);

    $task->update(['priority' => TaskPriority::High]);

    $after = $this->actingAs($user)->get(route('dashboard'));
    $after->assertSuccessful();
    $after->assertSee('Workspace Reactive Urgent Title', false);

    Carbon::setTestNow();
});

test('dashboard urgent now orders overdue tasks before non-overdue tasks', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-09 09:00:00'));
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    Task::factory()->for($user)->for($project)->create([
        'title' => 'Overdue First Task',
        'priority' => TaskPriority::Low,
        'status' => TaskStatus::ToDo,
        'end_datetime' => Carbon::parse('2026-04-08 14:00:00'),
        'completed_at' => null,
    ]);

    Task::factory()->for($user)->for($project)->create([
        'title' => 'Due Soon High Task',
        'priority' => TaskPriority::High,
        'status' => TaskStatus::ToDo,
        'end_datetime' => Carbon::parse('2026-04-10 14:00:00'),
        'completed_at' => null,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));
    $response->assertSuccessful();
    $html = (string) $response->getContent();

    expect(preg_match('/data-testid="dashboard-row-urgent-item"[\s\S]*?<p class="truncate text-sm font-semibold text-foreground">([^<]+)<\/p>/', $html, $firstUrgentMatch))->toBe(1);
    expect(trim((string) ($firstUrgentMatch[1] ?? '')))->toBe('Overdue First Task');

    Carbon::setTestNow();
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
    $response->assertSee('Show insights', false);
});

test('dashboard phase 2 sections render with calendar feed health data', function () {
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
    $response->assertSee('Show insights', false);
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

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertSuccessful();
    $response->assertSee('No-date Backlog', false);
    $response->assertSee('Show insights', false);
    $response->assertDontSee('Focus + Throughput', false);
    $response->assertDontSee('LLM Assistant Activity', false);
    $response->assertDontSee('Quick actions', false);
    $response->assertSee('No Date Backlog Task', false);

    expect(preg_match('/data-testid="dashboard-no-date-backlog-count"[^>]*>\s*(\d+)\s*</', $response->getContent(), $matches))->toBe(1);
    expect($matches[1])->toBe('1');
    $response->assertSee('data-testid="dashboard-row-no-date-backlog-task"', false);
});

test('dashboard no-date backlog shows see all in workspace when more than three backlog tasks exist', function () {
    $user = User::factory()->create();

    foreach (range(1, 4) as $index) {
        Task::factory()->for($user)->create([
            'title' => 'No Date Backlog '.$index,
            'status' => TaskStatus::ToDo,
            'start_datetime' => null,
            'end_datetime' => null,
            'completed_at' => null,
        ]);
    }

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertSuccessful();
    $response->assertSee('data-testid="dashboard-no-date-backlog-see-all"', false);
    $response->assertSee('See all in Workspace', false);
    expect(preg_match('/data-testid="dashboard-no-date-backlog-count"[^>]*>\s*(\d+)\s*</', $response->getContent(), $matches))->toBe(1);
    expect($matches[1])->toBe('4');
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
    $response->assertSee('data-testid="calendar-dot-legend"', false);
    $response->assertSee('data-testid="calendar-selected-day-agenda"', false);
    $response->assertSee('data-testid="calendar-agenda-scheduled-starts"', false);
    $response->assertSee('Agenda Urgent Task', false);
    $response->assertSee('Agenda Timed Event', false);

    expect(preg_match('/data-testid="calendar-agenda-summary-tasks"[^>]*>\s*(\d+)\s*</', $response->getContent(), $taskMatches))->toBe(1);
    expect((int) $taskMatches[1])->toBeGreaterThanOrEqual(1);

    expect(preg_match('/data-testid="calendar-agenda-summary-events"[^>]*>\s*(\d+)\s*</', $response->getContent(), $eventMatches))->toBe(1);
    expect((int) $eventMatches[1])->toBe(2);
});

test('dashboard calendar agenda includes manual and imported tasks without source filtering', function () {
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

    $response = $this->actingAs($user)->get(route('dashboard', ['date' => $selectedDate]));

    $response->assertSuccessful();
    $response->assertSee('Manual Agenda Task', false);
    $response->assertSee('Imported Agenda Task', false);

    expect(preg_match('/data-testid="calendar-agenda-summary-tasks"[^>]*>\s*(\d+)\s*</', $response->getContent(), $summaryMatches))->toBe(1);
    expect((int) $summaryMatches[1])->toBe(2);
});

test('dashboard selected date drives due and events panels', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-09 09:00:00'));
    $user = User::factory()->create();

    Task::factory()->for($user)->create([
        'title' => 'Due On Selected Day',
        'status' => TaskStatus::ToDo,
        'end_datetime' => Carbon::parse('2026-04-12 15:00:00'),
        'completed_at' => null,
    ]);

    Event::factory()->for($user)->create([
        'title' => 'Event On Selected Day',
        'status' => 'scheduled',
        'start_datetime' => Carbon::parse('2026-04-12 09:30:00'),
        'end_datetime' => Carbon::parse('2026-04-12 10:30:00'),
        'all_day' => false,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard', ['date' => '2026-04-12']));

    $response->assertSuccessful();
    $response->assertSee('Due On Selected Day', false);
    $response->assertSee('Event On Selected Day', false);

    expect(preg_match('/data-testid="dashboard-kpi-due_today-value"[^>]*>\s*(\d+)\s*</', $response->getContent(), $dueMatches))->toBe(1);
    expect($dueMatches[1])->toBe('1');

    Carbon::setTestNow();
});

test('dashboard workspace links preserve selected date context', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-09 09:00:00'));
    $user = User::factory()->create();
    $selectedDate = '2026-04-12';

    Task::factory()->for($user)->create([
        'title' => 'Selected Date Urgent Task',
        'priority' => TaskPriority::Urgent,
        'status' => TaskStatus::ToDo,
        'end_datetime' => Carbon::parse('2026-04-12 11:00:00'),
        'completed_at' => null,
    ]);

    Task::factory()->for($user)->create([
        'title' => 'Selected Date Backlog Task',
        'priority' => TaskPriority::High,
        'status' => TaskStatus::ToDo,
        'start_datetime' => null,
        'end_datetime' => null,
        'completed_at' => null,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard', ['date' => $selectedDate]));

    $response->assertSuccessful();
    $response->assertSee(route('workspace', ['date' => $selectedDate]), false);
    $response->assertSee('date=2026-04-12', false);
    $response->assertSee('view=list', false);
    $response->assertSee('agenda_focus=1', false);

    Carbon::setTestNow();
});

test('dashboard see all links for doing classes and recurring include workspace filters', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-09 09:00:00'));
    $user = User::factory()->create();
    $selectedDate = '2026-04-12';

    foreach (range(1, 4) as $index) {
        Task::factory()->for($user)->create([
            'title' => 'Doing Task '.$index,
            'status' => TaskStatus::Doing,
            'end_datetime' => Carbon::parse('2026-04-12 '.sprintf('%02d:00:00', 8 + $index)),
            'completed_at' => null,
        ]);
    }

    foreach (range(1, 4) as $index) {
        SchoolClass::factory()->for($user)->create([
            'subject_name' => 'Class '.$index,
            'start_time' => sprintf('%02d:00:00', 8 + $index),
            'end_time' => sprintf('%02d:00:00', 9 + $index),
            'start_datetime' => Carbon::parse('2026-04-12 '.sprintf('%02d:00:00', 8 + $index)),
            'end_datetime' => Carbon::parse('2026-04-12 '.sprintf('%02d:00:00', 9 + $index)),
        ]);
    }

    foreach (range(1, 4) as $index) {
        $task = Task::factory()->for($user)->create([
            'title' => 'Recurring Task '.$index,
            'status' => TaskStatus::ToDo,
            'end_datetime' => Carbon::parse('2026-04-12 '.sprintf('%02d:00:00', 12 + $index)),
            'completed_at' => null,
        ]);

        RecurringTask::factory()->create([
            'task_id' => $task->id,
        ]);
    }

    $response = $this->actingAs($user)->get(route('dashboard', ['date' => $selectedDate]));

    $response->assertSuccessful();
    $response->assertSee('data-testid="dashboard-doing-see-all"', false);
    $response->assertSee('type=tasks', false);
    $response->assertSee('status=doing', false);
    $response->assertSee('from_dashboard_filter=doing', false);

    $response->assertSee('data-testid="dashboard-today-classes-see-all"', false);
    $response->assertSee('type=classes', false);
    $response->assertSee('from_dashboard_filter=classes', false);

    $response->assertSee('data-testid="dashboard-recurring-see-all"', false);
    $response->assertSee('recurring=recurring', false);
    $response->assertSee('from_dashboard_filter=recurring', false);

    Carbon::setTestNow();
});

test('dashboard recurring section shows selected-day due count in header and recurring rows only', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-09 08:00:00'));
    $user = User::factory()->create();
    $selectedDate = '2026-04-12';

    $dueRecurringTask = Task::factory()->for($user)->create([
        'title' => 'Recurring Due Task',
        'status' => TaskStatus::ToDo,
        'end_datetime' => Carbon::parse('2026-04-12 13:00:00'),
        'completed_at' => null,
    ]);
    RecurringTask::factory()->create([
        'task_id' => $dueRecurringTask->id,
    ]);

    $completedRecurringTask = Task::factory()->for($user)->create([
        'title' => 'Recurring Completed Task',
        'status' => TaskStatus::Done,
        'end_datetime' => Carbon::parse('2026-04-12 10:00:00'),
        'completed_at' => Carbon::parse('2026-04-12 11:30:00'),
    ]);
    RecurringTask::factory()->create([
        'task_id' => $completedRecurringTask->id,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard', ['date' => $selectedDate]));

    $response->assertSuccessful();
    $response->assertSee('Repeating tasks on April 12', false);
    $response->assertSee('Recurring Due Task', false);
    $response->assertDontSee('Recurring Completed Task', false);

    expect(preg_match('/data-testid="dashboard-recurring-due-count"[^>]*>\s*(\d+)\s*</', $response->getContent(), $panelDueMatches))->toBe(1);
    expect($panelDueMatches[1])->toBe('1');

    Carbon::setTestNow();
});

test('dashboard recurring section excludes non-recurring and off-date tasks', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-09 08:00:00'));
    $user = User::factory()->create();
    $selectedDate = '2026-04-12';

    $recurringOnDate = Task::factory()->for($user)->create([
        'title' => 'Recurring On Date',
        'status' => TaskStatus::ToDo,
        'end_datetime' => Carbon::parse('2026-04-12 09:00:00'),
        'completed_at' => null,
    ]);
    RecurringTask::factory()->create([
        'task_id' => $recurringOnDate->id,
    ]);

    Task::factory()->for($user)->create([
        'title' => 'Non Recurring On Date',
        'status' => TaskStatus::ToDo,
        'end_datetime' => Carbon::parse('2026-04-12 12:00:00'),
        'completed_at' => null,
    ]);

    $recurringOffDate = Task::factory()->for($user)->create([
        'title' => 'Recurring Off Date',
        'status' => TaskStatus::ToDo,
        'end_datetime' => Carbon::parse('2026-04-13 09:00:00'),
        'completed_at' => null,
    ]);
    RecurringTask::factory()->create([
        'task_id' => $recurringOffDate->id,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard', ['date' => $selectedDate]));

    $response->assertSuccessful();
    $response->assertSee('Recurring On Date', false);
    expect(substr_count((string) $response->getContent(), 'data-testid="dashboard-row-recurring-task"'))->toBe(1);

    Carbon::setTestNow();
});

test('dashboard recurring section includes recurring task without due datetime when occurrence matches selected day', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-09 08:00:00'));
    $user = User::factory()->create();
    $selectedDate = '2026-04-12';

    $recurringTask = Task::factory()->for($user)->create([
        'title' => 'Recurring Without Due Datetime',
        'status' => TaskStatus::ToDo,
        'start_datetime' => null,
        'end_datetime' => null,
        'completed_at' => null,
    ]);
    RecurringTask::factory()->create([
        'task_id' => $recurringTask->id,
        'recurrence_type' => 'daily',
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-04-10 09:00:00'),
        'end_datetime' => null,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard', ['date' => $selectedDate]));

    $response->assertSuccessful();
    $response->assertSee('Recurring Without Due Datetime', false);
    expect(substr_count((string) $response->getContent(), 'data-testid="dashboard-row-recurring-task"'))->toBe(1);

    Carbon::setTestNow();
});

test('dashboard recurring section shows three rows and see all link when more due tasks exist', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-09 08:00:00'));
    $user = User::factory()->create();
    $selectedDate = '2026-04-12';

    foreach (range(1, 4) as $index) {
        $task = Task::factory()->for($user)->create([
            'title' => 'Recurring Due '.$index,
            'status' => TaskStatus::ToDo,
            'end_datetime' => Carbon::parse('2026-04-12 '.sprintf('%02d:00:00', 8 + $index)),
            'completed_at' => null,
        ]);

        RecurringTask::factory()->create([
            'task_id' => $task->id,
        ]);
    }

    $response = $this->actingAs($user)->get(route('dashboard', ['date' => $selectedDate]));

    $response->assertSuccessful();
    expect(substr_count((string) $response->getContent(), 'data-testid="dashboard-row-recurring-task"'))->toBe(3);
    $response->assertSee('data-testid="dashboard-recurring-see-all"', false);
    expect(preg_match('/data-testid="dashboard-recurring-due-count"[^>]*>\s*(\d+)\s*</', $response->getContent(), $matches))->toBe(1);
    expect($matches[1])->toBe('4');

    Carbon::setTestNow();
});

test('dashboard recurring section omits see all link when due tasks are within display limit', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-09 08:00:00'));
    $user = User::factory()->create();
    $selectedDate = '2026-04-12';

    foreach (range(1, 3) as $index) {
        $task = Task::factory()->for($user)->create([
            'title' => 'Recurring Due '.$index,
            'status' => TaskStatus::ToDo,
            'end_datetime' => Carbon::parse('2026-04-12 '.sprintf('%02d:00:00', 9 + $index)),
            'completed_at' => null,
        ]);

        RecurringTask::factory()->create([
            'task_id' => $task->id,
        ]);
    }

    $response = $this->actingAs($user)->get(route('dashboard', ['date' => $selectedDate]));

    $response->assertSuccessful();
    expect(substr_count((string) $response->getContent(), 'data-testid="dashboard-row-recurring-task"'))->toBe(3);
    $response->assertDontSee('data-testid="dashboard-recurring-see-all"', false);

    Carbon::setTestNow();
});

test('dashboard recurring section shows simplified empty state copy', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-09 08:00:00'));
    $user = User::factory()->create();
    $selectedDate = '2026-04-12';

    $response = $this->actingAs($user)->get(route('dashboard', ['date' => $selectedDate]));

    $response->assertSuccessful();
    $response->assertSee('No repeating tasks right now.', false);

    Carbon::setTestNow();
});
