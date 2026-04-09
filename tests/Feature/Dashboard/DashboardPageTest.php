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
use App\Models\Project;
use App\Models\Task;
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
    $response->assertSee('Calendar Feed Health', false);
    $response->assertSee('Brightspace Feed', false);
    $response->assertSee('Collaboration Target Task', false);

    expect(preg_match('/data-testid="dashboard-collab-pending-invites"[^>]*>\s*(\d+)\s*</', $response->getContent(), $matches))->toBe(1);
    expect($matches[1])->toBe('1');
});
