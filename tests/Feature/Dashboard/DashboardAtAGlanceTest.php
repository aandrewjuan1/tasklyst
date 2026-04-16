<?php

use App\Enums\EventStatus;
use App\Enums\TaskStatus;
use App\Models\Event;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-04-09 12:00:00', config('app.timezone')));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('renders at-a-glance with task and event titles and workspace link for today', function () {
    $user = User::factory()->create();

    Task::factory()->for($user)->create([
        'title' => 'AtAGlance Overdue Task',
        'status' => TaskStatus::ToDo,
        'end_datetime' => now()->subHours(2),
        'completed_at' => null,
    ]);

    Task::factory()->for($user)->create([
        'title' => 'AtAGlance Doing Task',
        'status' => TaskStatus::Doing,
        'end_datetime' => now()->addDay(),
        'completed_at' => null,
    ]);

    Task::factory()->for($user)->create([
        'title' => 'AtAGlance Due Today Task',
        'status' => TaskStatus::ToDo,
        'end_datetime' => now()->startOfDay()->addHours(16),
        'completed_at' => null,
    ]);

    Event::factory()->for($user)->create([
        'title' => 'AtAGlance Today Event',
        'status' => EventStatus::Scheduled,
        'start_datetime' => now()->startOfDay()->addHours(14),
        'end_datetime' => now()->startOfDay()->addHours(15),
        'all_day' => false,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertSuccessful();
    $response->assertSee('AtAGlance Overdue Task', false);
    $response->assertSee('AtAGlance Doing Task', false);
    $response->assertSee('AtAGlance Due Today Task', false);
    $response->assertSee('AtAGlance Today Event', false);
    $response->assertSee('date='.now()->toDateString(), false);
});

it('shows empty state copy when there are no matching items', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertSuccessful();
});
