<?php

use App\Enums\TaskRecurrenceType;
use App\Enums\TaskStatus;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('recurring task with past parent end_datetime still appears on today workspace list', function (): void {
    $task = Task::factory()->for($this->user)->create([
        'title' => 'RecurringPastEndWorkspace',
        'start_datetime' => now()->subWeek()->startOfDay(),
        'end_datetime' => now()->subWeek()->endOfDay(),
        'status' => TaskStatus::ToDo,
    ]);

    RecurringTask::factory()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => now()->subDays(30)->startOfDay(),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString());

    $titles = $component->instance()->getAllListEntries()
        ->pluck('item.title')
        ->all();

    expect($titles)->toContain('RecurringPastEndWorkspace');
});

test('workspace list dedupes overdue task that also matches selected date', function (): void {
    $yesterday = now()->subDay()->startOfDay();
    $task = Task::factory()->for($this->user)->create([
        'title' => 'OverlapDup',
        'start_datetime' => $yesterday->copy()->subDay(),
        'end_datetime' => $yesterday->copy()->addHours(12),
        'status' => TaskStatus::ToDo,
    ]);

    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', $yesterday->toDateString());

    $entries = $component->instance()->getAllListEntries();
    $taskRows = $entries->filter(fn (array $e): bool => $e['kind'] === 'task' && (int) $e['item']->id === $task->id);

    expect($taskRows)->toHaveCount(1);
});

test('workspace list view shows empty hint on item creation when there are no items for the selected day', function (): void {
    $this->actingAs($this->user);

    $this->get(route('workspace', ['view' => 'list']))
        ->assertSuccessful()
        ->assertSee('data-test="workspace-item-creation"', false)
        ->assertSee('data-test="workspace-item-creation-empty"', false)
        ->assertSee(
            __('No tasks, projects, or events for :date', ['date' => __('today')]),
            false
        );
});

test('workspace list exposes planner sections in expected order', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-13 12:00:00'));

    $this->actingAs($this->user);

    Task::factory()->for($this->user)->create([
        'title' => 'Overdue Section Task',
        'start_datetime' => Carbon::parse('2026-04-10 08:00:00'),
        'end_datetime' => Carbon::parse('2026-04-10 09:00:00'),
        'status' => TaskStatus::ToDo,
    ]);

    Task::factory()->for($this->user)->create([
        'title' => 'Today Section Task',
        'start_datetime' => Carbon::parse('2026-04-13 10:00:00'),
        'end_datetime' => Carbon::parse('2026-04-13 18:00:00'),
        'status' => TaskStatus::ToDo,
    ]);

    Task::factory()->for($this->user)->create([
        'title' => 'Tomorrow Section Task',
        'start_datetime' => Carbon::parse('2026-04-14 10:00:00'),
        'end_datetime' => Carbon::parse('2026-04-14 11:00:00'),
        'status' => TaskStatus::ToDo,
    ]);

    Task::factory()->for($this->user)->create([
        'title' => 'Upcoming Section Task',
        'start_datetime' => Carbon::parse('2026-04-16 10:00:00'),
        'end_datetime' => Carbon::parse('2026-04-16 11:00:00'),
        'status' => TaskStatus::ToDo,
    ]);

    $sections = Livewire::test('pages::workspace.index')
        ->set('searchScope', 'all_items')
        ->set('selectedDate', '2026-04-16')
        ->instance()
        ->getSectionedListEntries()
        ->pluck('plannerSection')
        ->unique()
        ->values()
        ->all();

    expect($sections)->toBe(['overdue', 'today', 'tomorrow', 'upcoming']);
});

test('workspace list orders overdue tasks by urgency first', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-13 12:00:00'));

    $this->actingAs($this->user);

    Task::factory()->for($this->user)->create([
        'title' => 'Low Later Overdue',
        'priority' => 'low',
        'start_datetime' => Carbon::parse('2026-04-12 08:00:00'),
        'end_datetime' => Carbon::parse('2026-04-12 17:00:00'),
        'status' => TaskStatus::ToDo,
    ]);

    Task::factory()->for($this->user)->create([
        'title' => 'Urgent Earlier Overdue',
        'priority' => 'urgent',
        'start_datetime' => Carbon::parse('2026-04-11 08:00:00'),
        'end_datetime' => Carbon::parse('2026-04-11 09:00:00'),
        'status' => TaskStatus::ToDo,
    ]);

    $titles = Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-04-13')
        ->instance()
        ->getSectionedListEntries()
        ->filter(fn (array $entry): bool => $entry['plannerSection'] === 'overdue' && $entry['kind'] === 'task')
        ->pluck('item.title')
        ->values()
        ->all();

    expect($titles)->toBe([
        'Urgent Earlier Overdue',
        'Low Later Overdue',
    ]);
});

test('workspace list quick section focus filters entries', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-13 12:00:00'));

    $this->actingAs($this->user);

    Task::factory()->for($this->user)->create([
        'title' => 'Today Focus Item',
        'start_datetime' => Carbon::parse('2026-04-13 09:00:00'),
        'end_datetime' => Carbon::parse('2026-04-13 17:00:00'),
        'status' => TaskStatus::ToDo,
    ]);

    Task::factory()->for($this->user)->create([
        'title' => 'Tomorrow Focus Item',
        'start_datetime' => Carbon::parse('2026-04-14 09:00:00'),
        'end_datetime' => Carbon::parse('2026-04-14 17:00:00'),
        'status' => TaskStatus::ToDo,
    ]);

    $component = Livewire::test('pages::workspace.index')
        ->set('searchScope', 'all_items')
        ->set('selectedDate', '2026-04-14')
        ->set('quickSection', 'tomorrow');

    $titles = $component->instance()
        ->getSectionedListEntries()
        ->pluck('item.title')
        ->all();

    expect($titles)->toContain('Tomorrow Focus Item')
        ->and($titles)->not->toContain('Today Focus Item');
});

test('workspace hides completed tasks by default and shows them when toggle is enabled', function (): void {
    $this->actingAs($this->user);

    Task::factory()->for($this->user)->create([
        'title' => 'Hidden Done Task',
        'status' => TaskStatus::Done,
        'start_datetime' => now()->subHour(),
        'end_datetime' => now()->addHour(),
    ]);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('showCompleted', '0');

    $activeTitles = $component->instance()
        ->getAllListEntries()
        ->pluck('item.title')
        ->all();

    expect($activeTitles)->not->toContain('Hidden Done Task');

    $component->set('showCompleted', '1');

    $activeTitlesWhenShowingCompleted = $component->instance()
        ->getAllListEntries()
        ->pluck('item.title')
        ->all();

    $completedTitles = $component->instance()
        ->completedListEntries()
        ->pluck('item.title')
        ->all();

    expect($activeTitlesWhenShowingCompleted)->not->toContain('Hidden Done Task')
        ->and($completedTitles)->toContain('Hidden Done Task');
});
