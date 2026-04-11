<?php

use App\Enums\EventStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskSourceType;
use App\Enums\TaskStatus;
use App\Models\CalendarFeed;
use App\Models\Event;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('workspace calendar supports dashboard contract methods', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-04-09')
        ->call('navigateSelectedDate', 1)
        ->assertSet('selectedDate', '2026-04-10');
});

test('workspace calendar renders selected day agenda without source filtering', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-09 09:00:00'));

    $user = User::factory()->create();
    $feed = CalendarFeed::query()->create([
        'user_id' => $user->id,
        'name' => 'Workspace Imported Feed',
        'feed_url' => 'https://example.com/workspace-calendar.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
    ]);

    Task::factory()->for($user)->create([
        'title' => 'Workspace Manual Agenda Task',
        'priority' => TaskPriority::High,
        'status' => TaskStatus::ToDo,
        'source_type' => TaskSourceType::Manual->value,
        'end_datetime' => Carbon::parse('2026-04-09 13:00:00'),
        'completed_at' => null,
    ]);

    Task::factory()->for($user)->create([
        'title' => 'Workspace Imported Agenda Task',
        'priority' => TaskPriority::Urgent,
        'status' => TaskStatus::ToDo,
        'source_type' => TaskSourceType::Brightspace->value,
        'source_id' => 'workspace-imported-1',
        'calendar_feed_id' => $feed->id,
        'end_datetime' => Carbon::parse('2026-04-09 14:00:00'),
        'completed_at' => null,
    ]);

    Event::factory()->for($user)->create([
        'title' => 'Workspace Agenda Event',
        'status' => EventStatus::Scheduled,
        'start_datetime' => Carbon::parse('2026-04-09 12:00:00'),
        'end_datetime' => Carbon::parse('2026-04-09 13:00:00'),
        'all_day' => false,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-04-09')
        ->assertSee('data-testid="calendar-selected-day-agenda"', false)
        ->assertSee('Workspace Manual Agenda Task')
        ->assertSee('Workspace Imported Agenda Task')
        ->assertSet('selectedDayAgenda.summary.tasks', 2)
        ->assertSee('Workspace Agenda Event');
});

test('selected day agenda lists overdue tasks in overdue section and not in urgent', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-09 15:00:00'));

    $user = User::factory()->create();

    Task::factory()->for($user)->create([
        'title' => 'Past Due High Priority Task',
        'priority' => TaskPriority::High,
        'status' => TaskStatus::ToDo,
        'source_type' => TaskSourceType::Manual->value,
        'end_datetime' => Carbon::parse('2026-04-09 12:00:00'),
        'completed_at' => null,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-04-09')
        ->assertSet('selectedDayAgenda.summary.overdue', 1)
        ->assertCount('selectedDayAgenda.overdueTasks', 1)
        ->assertSet('selectedDayAgenda.overdueTasks.0.title', 'Past Due High Priority Task')
        ->assertCount('selectedDayAgenda.urgentTasks', 0)
        ->assertSee('data-testid="calendar-agenda-overdue-tasks"', false)
        ->assertSee('Past Due High Priority Task');
});
