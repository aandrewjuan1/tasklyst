<?php

use App\Enums\EventStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskSourceType;
use App\Enums\TaskStatus;
use App\Models\CalendarFeed;
use App\Models\Event;
use App\Models\SchoolClass;
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

test('jumpCalendarToToday sets selected date to today', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-15 12:00:00'));

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-03-01')
        ->call('jumpCalendarToToday')
        ->assertSet('selectedDate', '2026-04-15');

    Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-04-15')
        ->call('jumpCalendarToToday')
        ->assertSet('selectedDate', '2026-04-15');
});

test('jumpCalendarToToday clears month browse so the grid returns to the current month', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-13 12:00:00'));

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-04-13')
        ->set('calendarViewYear', 2026)
        ->set('calendarViewMonth', 6)
        ->call('jumpCalendarToToday')
        ->assertSet('calendarViewYear', null)
        ->assertSet('calendarViewMonth', null)
        ->assertSet('selectedDate', '2026-04-13');
});

test('Jump to today button SSR disables only when the visible month is today\'s month and the selected day is today', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-13 12:00:00'));

    $user = User::factory()->create();
    $this->actingAs($user);

    // Boolean `disabled` only — not `x-bind:disabled` (which also contains the substring "disabled").
    $ssrDisabledAttr = '/data-testid="calendar-jump-to-today"[^>]*\sdisabled(?:\s|>|\/)/';

    $htmlOnToday = Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-04-13')
        ->html();

    expect(preg_match($ssrDisabledAttr, $htmlOnToday) === 1)->toBeTrue();

    $htmlOtherDaySameMonth = Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-04-12')
        ->html();

    expect(preg_match($ssrDisabledAttr, $htmlOtherDaySameMonth) === 0)->toBeTrue();

    $htmlBrowsingJuneWhileSelectedToday = Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-04-13')
        ->set('calendarViewYear', 2026)
        ->set('calendarViewMonth', 6)
        ->html();

    expect(preg_match($ssrDisabledAttr, $htmlBrowsingJuneWhileSelectedToday) === 0)->toBeTrue();
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
        ->assertSee('data-testid="calendar-agenda-scheduled-starts"', false)
        ->assertSee('Workspace Manual Agenda Task')
        ->assertSee('Workspace Imported Agenda Task')
        ->assertSet('selectedDayAgenda.summary.tasks', 2)
        ->assertCount('selectedDayAgenda.dueDayTasks', 2)
        ->assertSee('Workspace Agenda Event');
});

test('selected day agenda lists overdue tasks in overdue section and not in due-day tasks', function (): void {
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
        ->assertCount('selectedDayAgenda.dueDayTasks', 0)
        ->assertSee('data-testid="calendar-agenda-overdue-tasks"', false)
        ->assertSee('Past Due High Priority Task');
});

test('calendar month meta and agenda include school classes on the selected day', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-09 12:00:00'));

    $user = User::factory()->create();
    $this->actingAs($user);

    SchoolClass::factory()->for($user)->create([
        'subject_name' => 'Calendar Parity Class',
        'start_datetime' => Carbon::parse('2026-04-09 09:00:00'),
        'end_datetime' => Carbon::parse('2026-04-09 10:30:00'),
    ]);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-04-09')
        ->set('calendarViewYear', 2026)
        ->set('calendarViewMonth', 4);

    $meta = $component->get('calendarMonthMeta');
    expect((int) ($meta['2026-04-09']['school_class_count'] ?? 0))->toBeGreaterThan(0);

    $agenda = $component->get('selectedDayAgenda');
    expect($agenda['summary']['classes'])->toBeGreaterThan(0);
    expect(collect($agenda['schoolClasses'])->pluck('title')->all())->toContain('Calendar Parity Class');
});

test('dashboard selected day agenda school class urls use agenda_focus and omit type filter', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-09 15:00:00'));

    $user = User::factory()->create();

    $schoolClass = SchoolClass::factory()->for($user)->create([
        'subject_name' => 'Dashboard School Class',
        'start_datetime' => Carbon::parse('2026-04-09 10:00:00'),
        'end_datetime' => Carbon::parse('2026-04-09 11:30:00'),
    ]);

    $this->actingAs($user);

    $agenda = Livewire::test('pages::dashboard.index')
        ->set('selectedDate', '2026-04-09')
        ->get('selectedDayAgenda');

    $row = collect($agenda['schoolClasses'] ?? [])->firstWhere('title', 'Dashboard School Class');
    expect($row)->not->toBeNull();
    $url = $row['workspace_url'];
    expect($url)->toContain('school_class='.$schoolClass->id)
        ->and($url)->toContain('agenda_focus=1')
        ->and($url)->toContain('view=list')
        ->and($url)->not->toContain('type=');
});

test('workspace selected day agenda school class urls use type classes and school_class id', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-09 15:00:00'));

    $user = User::factory()->create();

    $schoolClass = SchoolClass::factory()->for($user)->create([
        'subject_name' => 'Workspace Agenda Class',
        'start_datetime' => Carbon::parse('2026-04-09 10:00:00'),
        'end_datetime' => Carbon::parse('2026-04-09 11:30:00'),
    ]);

    $this->actingAs($user);

    $agenda = Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-04-09')
        ->get('selectedDayAgenda');

    $row = collect($agenda['schoolClasses'] ?? [])->firstWhere('title', 'Workspace Agenda Class');
    expect($row)->not->toBeNull();
    expect($row['workspace_url'])->toContain('school_class='.$schoolClass->id)
        ->and($row['workspace_url'])->toContain('type=classes')
        ->and($row['focus_kind'])->toBe('schoolClass')
        ->and($row['focus_id'])->toBe($schoolClass->id);
});

test('dashboard selected day agenda workspace urls use agenda_focus and omit type filter', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-09 15:00:00'));

    $user = User::factory()->create();

    $task = Task::factory()->for($user)->create([
        'title' => 'Dashboard Agenda Task',
        'priority' => TaskPriority::High,
        'status' => TaskStatus::ToDo,
        'source_type' => TaskSourceType::Manual->value,
        'end_datetime' => Carbon::parse('2026-04-09 18:00:00'),
        'completed_at' => null,
    ]);

    $this->actingAs($user);

    $agenda = Livewire::test('pages::dashboard.index')
        ->set('selectedDate', '2026-04-09')
        ->get('selectedDayAgenda');

    $dueRows = collect($agenda['dueDayTasks'] ?? []);
    expect($dueRows)->not->toBeEmpty();
    $url = $dueRows->first()['workspace_url'];
    expect($url)->toContain('task='.$task->id)
        ->and($url)->toContain('agenda_focus=1')
        ->and($url)->toContain('view=list')
        ->and($url)->not->toContain('type=');
});

test('selected day agenda workspace urls use id deep links not search query', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-09 15:00:00'));

    $user = User::factory()->create();

    $task = Task::factory()->for($user)->create([
        'title' => 'Agenda URL Overdue Task',
        'priority' => TaskPriority::High,
        'status' => TaskStatus::ToDo,
        'source_type' => TaskSourceType::Manual->value,
        'end_datetime' => Carbon::parse('2026-04-09 12:00:00'),
        'completed_at' => null,
    ]);

    $event = Event::factory()->for($user)->create([
        'title' => 'Agenda URL Starting Event',
        'status' => EventStatus::Scheduled,
        'start_datetime' => Carbon::parse('2026-04-09 10:00:00'),
        'end_datetime' => Carbon::parse('2026-04-09 11:00:00'),
        'all_day' => false,
    ]);

    $this->actingAs($user);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-04-09');

    $agenda = $component->instance()->selectedDayAgenda;

    $overdueUrl = $agenda['overdueTasks'][0]['workspace_url'];
    expect($overdueUrl)->toContain('task='.$task->id)
        ->and($overdueUrl)->toContain('view=list')
        ->and($overdueUrl)->not->toContain('q=');

    $eventRow = collect($agenda['scheduledStarts'])->firstWhere('title', 'Agenda URL Starting Event');
    expect($eventRow)->not->toBeNull()
        ->and($eventRow['workspace_url'])->toContain('event='.$event->id)
        ->and($eventRow['workspace_url'])->toContain('view=list')
        ->and($eventRow['focus_kind'])->toBe('event')
        ->and($eventRow['focus_id'])->toBe($event->id);
});

test('workspace index includes calendar dot legend and mobile date bar', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::workspace.index')
        ->assertSee('data-testid="calendar-dot-legend"', false)
        ->assertSee('data-testid="workspace-mobile-selected-date-bar"', false)
        ->assertSee('id="workspace-mobile-calendar-anchor"', false);
});

test('selected day agenda times use 12-hour clock in the sidebar', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-09 10:00:00'));

    $user = User::factory()->create();
    Task::factory()->for($user)->create([
        'title' => 'Evening due task',
        'priority' => TaskPriority::Medium,
        'status' => TaskStatus::ToDo,
        'source_type' => TaskSourceType::Manual->value,
        'end_datetime' => Carbon::parse('2026-04-09 20:53:00'),
        'completed_at' => null,
    ]);

    $this->actingAs($user);

    $agenda = Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-04-09')
        ->get('selectedDayAgenda');

    $dueRows = collect($agenda['dueDayTasks'] ?? []);
    expect($dueRows)->not->toBeEmpty();
    expect($dueRows->first()['time'])->toContain('8:53')->and($dueRows->first()['time'])->toContain('PM');
    expect($dueRows->first()['time_label'])->toBe(__('Due'));
});
