<?php

use App\Enums\EventStatus;
use App\Enums\TaskSourceType;
use App\Enums\TaskStatus;
use App\Models\Event;
use App\Models\Project;
use App\Models\SchoolClass;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('workspace filter strip shows a subtle hint when no filters are active', function (): void {
    $this->actingAs($this->user);

    $this->get(route('workspace'))
        ->assertSuccessful()
        ->assertSee(__('No active filters.'), false);
});

test('clearing the last task-specific filter widens show filter to all types when item type was auto-set', function (): void {
    $project = Project::factory()->for($this->user)->create([
        'name' => 'VisibleAfterClearFilter',
        'start_datetime' => null,
        'end_datetime' => null,
    ]);

    Task::factory()->for($this->user)->create([
        'title' => 'FilteredTask',
        'project_id' => null,
        'start_datetime' => null,
        'end_datetime' => null,
        'status' => \App\Enums\TaskStatus::ToDo,
    ]);

    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('filterTaskStatus', \App\Enums\TaskStatus::ToDo->value);

    expect($component->get('filterItemType'))->toBe('tasks');
    expect($component->instance()->projects()->pluck('name'))->not->toContain('VisibleAfterClearFilter');

    $component->call('clearFilter', 'taskStatus');

    expect($component->get('filterItemType'))->toBeNull();
    expect($component->instance()->projects()->pluck('name'))->toContain('VisibleAfterClearFilter');
});

test('recurring-only filter hides overdue bucket', function (): void {
    Task::factory()->for($this->user)->create([
        'title' => 'OverdueOneTime',
        'start_datetime' => now()->subDays(3),
        'end_datetime' => now()->subDay(),
    ]);

    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('filterRecurring', 'recurring');

    expect($component->instance()->overdue())->toBeEmpty();
});

test('task source filter brightspace shows only Brightspace-sourced tasks', function (): void {
    Task::factory()->for($this->user)->create([
        'title' => 'BrightspaceFilteredTask',
        'status' => TaskStatus::ToDo,
        'source_type' => TaskSourceType::Brightspace,
        'start_datetime' => null,
        'end_datetime' => null,
    ]);
    Task::factory()->for($this->user)->create([
        'title' => 'ManualFilteredTask',
        'status' => TaskStatus::ToDo,
        'source_type' => TaskSourceType::Manual,
        'start_datetime' => null,
        'end_datetime' => null,
    ]);

    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('filterTaskSource', 'brightspace');

    $titles = $component->instance()->tasks()->pluck('title');
    expect($titles)->toContain('BrightspaceFilteredTask')
        ->and($titles)->not->toContain('ManualFilteredTask');
});

test('task source filter manual excludes Brightspace-sourced tasks', function (): void {
    Task::factory()->for($this->user)->create([
        'title' => 'BrightspaceExcludedTask',
        'status' => TaskStatus::ToDo,
        'source_type' => TaskSourceType::Brightspace,
        'start_datetime' => null,
        'end_datetime' => null,
    ]);
    Task::factory()->for($this->user)->create([
        'title' => 'ManualIncludedTask',
        'status' => TaskStatus::ToDo,
        'source_type' => TaskSourceType::Manual,
        'start_datetime' => null,
        'end_datetime' => null,
    ]);

    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('filterTaskSource', 'manual');

    $titles = $component->instance()->tasks()->pluck('title');
    expect($titles)->toContain('ManualIncludedTask')
        ->and($titles)->not->toContain('BrightspaceExcludedTask');
});

test('tag filter includes project when a child task has that tag', function (): void {
    $tag = Tag::factory()->for($this->user)->create(['name' => 'WorkspaceFilterTag']);
    $project = Project::factory()->for($this->user)->create([
        'name' => 'TaggedChildProject',
        'start_datetime' => null,
        'end_datetime' => null,
    ]);
    $task = Task::factory()->for($this->user)->create([
        'project_id' => $project->id,
        'start_datetime' => null,
        'end_datetime' => null,
    ]);
    $task->tags()->attach($tag);

    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('filterTagIds', [$tag->id]);

    expect($component->instance()->projects()->pluck('id'))->toContain($project->id);
});

test('completed entries include done tasks, completed events, and ended projects when toggle is enabled', function (): void {
    $this->actingAs($this->user);

    Task::factory()->for($this->user)->create([
        'title' => 'Completed Scope Task',
        'status' => TaskStatus::Done,
        'start_datetime' => now()->subHour(),
        'end_datetime' => now()->addHour(),
    ]);

    Event::factory()->for($this->user)->create([
        'title' => 'Completed Scope Event',
        'status' => EventStatus::Completed,
        'start_datetime' => now()->subHour(),
        'end_datetime' => now()->addHour(),
        'all_day' => false,
    ]);

    Project::factory()->for($this->user)->create([
        'name' => 'Completed Scope Project',
        'start_datetime' => now()->subWeek(),
        'end_datetime' => now()->subDay(),
    ]);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('showCompleted', '1');

    $completedTitles = $component->instance()
        ->completedListEntries()
        ->map(fn (array $entry): string => $entry['kind'] === 'project' ? $entry['item']->name : $entry['item']->title)
        ->values()
        ->all();

    expect($completedTitles)
        ->toContain('Completed Scope Task')
        ->toContain('Completed Scope Event')
        ->toContain('Completed Scope Project');
});

test('selecting done task status auto-enables completed visibility', function (): void {
    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index')
        ->set('showCompleted', '0')
        ->set('filterTaskStatus', TaskStatus::Done->value);

    expect($component->get('showCompleted'))->toBe('1');
});

test('selecting completed or cancelled event status auto-enables completed visibility', function (): void {
    $this->actingAs($this->user);

    $completedComponent = Livewire::test('pages::workspace.index')
        ->set('showCompleted', '0')
        ->set('filterEventStatus', EventStatus::Completed->value);

    expect($completedComponent->get('showCompleted'))->toBe('1');

    $cancelledComponent = Livewire::test('pages::workspace.index')
        ->set('showCompleted', '0')
        ->set('filterEventStatus', EventStatus::Cancelled->value);

    expect($cancelledComponent->get('showCompleted'))->toBe('1');
});

test('non-completed status filters do not auto-enable completed visibility', function (): void {
    $this->actingAs($this->user);

    $taskComponent = Livewire::test('pages::workspace.index')
        ->set('showCompleted', '0')
        ->set('filterTaskStatus', TaskStatus::ToDo->value);

    expect($taskComponent->get('showCompleted'))->toBe('0');

    $eventComponent = Livewire::test('pages::workspace.index')
        ->set('showCompleted', '0')
        ->set('filterEventStatus', EventStatus::Scheduled->value);

    expect($eventComponent->get('showCompleted'))->toBe('0');
});

test('workspace shows contextual toast when opened from dashboard doing filter redirect', function (): void {
    $this->actingAs($this->user);

    Livewire::withQueryParams([
        'date' => now()->toDateString(),
        'view' => 'list',
        'type' => 'tasks',
        'status' => 'doing',
        'from_dashboard_filter' => 'doing',
    ])
        ->test('pages::workspace.index')
        ->assertDispatched('toast', type: 'info', message: 'Showing Doing tasks.');
});

test('workspace shows contextual toast when opened from dashboard classes filter redirect', function (): void {
    $this->actingAs($this->user);

    Livewire::withQueryParams([
        'date' => now()->toDateString(),
        'view' => 'list',
        'type' => 'classes',
        'from_dashboard_filter' => 'classes',
    ])
        ->test('pages::workspace.index')
        ->assertDispatched('toast', type: 'info', message: 'Showing Classes.');
});

test('workspace shows contextual toast when opened from dashboard recurring filter redirect', function (): void {
    $this->actingAs($this->user);

    Livewire::withQueryParams([
        'date' => now()->toDateString(),
        'view' => 'list',
        'type' => 'tasks',
        'recurring' => 'recurring',
        'from_dashboard_filter' => 'recurring',
    ])
        ->test('pages::workspace.index')
        ->assertDispatched('toast', type: 'info', message: 'Showing Recurring tasks.');
});

test('creating an item outside selected date scope shows visibility guidance toast', function (): void {
    $this->actingAs($this->user);

    Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-04-20')
        ->call('createProject', [
            'name' => 'Future project hidden from selected date',
            'startDatetime' => '2026-04-22T09:00:00',
            'endDatetime' => '2026-04-23T09:00:00',
        ])
        ->assertDispatched('toast', type: 'info', message: 'Item moved out of this date view. Pick its date or switch search to all items.');
});

test('due state filter is exposed via workspace filter payload', function (): void {
    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index')
        ->set('filterDueState', 'due');

    expect($component->instance()->getFilters()['dueState'])->toBe('due');
});

test('due state hides overdue bucket and keeps selected-date entries', function (): void {
    $this->actingAs($this->user);

    $today = now()->startOfDay();

    Task::factory()->for($this->user)->create([
        'title' => 'DueState Overdue Task',
        'status' => TaskStatus::ToDo,
        'start_datetime' => $today->copy()->subDays(3),
        'end_datetime' => $today->copy()->subDay()->setTime(12, 0),
    ]);

    Task::factory()->for($this->user)->create([
        'title' => 'DueState Due Task',
        'status' => TaskStatus::ToDo,
        'start_datetime' => $today->copy()->setTime(9, 0),
        'end_datetime' => $today->copy()->setTime(16, 0),
    ]);

    Task::factory()->for($this->user)->create([
        'title' => 'DueState Tomorrow Task',
        'status' => TaskStatus::ToDo,
        'start_datetime' => $today->copy()->addDay()->setTime(9, 0),
        'end_datetime' => $today->copy()->addDay()->setTime(16, 0),
    ]);

    Project::factory()->for($this->user)->create([
        'name' => 'DueState Project',
        'start_datetime' => $today->copy()->setTime(8, 0),
        'end_datetime' => $today->copy()->setTime(18, 0),
    ]);

    SchoolClass::factory()->for($this->user)->create([
        'subject_name' => 'DueState Class',
        'start_datetime' => now()->subDay(),
        'end_datetime' => now()->addDay(),
    ]);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', $today->toDateString())
        ->set('filterDueState', 'due');

    expect($component->instance()->overdue())->toBeEmpty();
    expect($component->instance()->tasks()->pluck('title'))->toContain('DueState Due Task')
        ->not->toContain('DueState Overdue Task')
        ->not->toContain('DueState Tomorrow Task');
    expect($component->instance()->projects()->pluck('name'))->toContain('DueState Project');
    expect($component->instance()->schoolClassesForWorkspaceList()->pluck('subject_name'))->toContain('DueState Class');
});

test('overdue state suppresses regular item collections and keeps overdue bucket', function (): void {
    $this->actingAs($this->user);

    Task::factory()->for($this->user)->create([
        'title' => 'OverdueState Task',
        'status' => TaskStatus::ToDo,
        'start_datetime' => now()->subDays(2),
        'end_datetime' => now()->subDay(),
    ]);

    Project::factory()->for($this->user)->create([
        'name' => 'OverdueState Project',
        'start_datetime' => now()->subHour(),
        'end_datetime' => now()->addHour(),
    ]);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('filterDueState', 'overdue');

    expect($component->instance()->overdue())->not->toBeEmpty();
    expect($component->instance()->tasks())->toBeEmpty();
    expect($component->instance()->projects())->toBeEmpty();
    expect($component->instance()->schoolClassesForWorkspaceList())->toBeEmpty();
});

test('due state participates in active filter detection', function (): void {
    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index')
        ->set('filterDueState', 'overdue');

    expect($component->instance()->hasActiveFilters())->toBeTrue();
    expect($component->instance()->hasActiveTaskBoardFilters())->toBeTrue();
});

test('clear all filters resets due state filter', function (): void {
    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index')
        ->set('filterDueState', 'due')
        ->call('clearAllFilters');

    expect($component->get('filterDueState'))->toBeNull();
    expect($component->instance()->getFilters()['dueState'])->toBeNull();
});

test('when filter pill is visible in both list and kanban workspace views', function (): void {
    $this->actingAs($this->user);

    $this->get(route('workspace', [
        'date' => now()->toDateString(),
        'view' => 'list',
    ]))
        ->assertSuccessful()
        ->assertSee('When: Any', false);

    $this->get(route('workspace', [
        'date' => now()->toDateString(),
        'view' => 'kanban',
    ]))
        ->assertSuccessful()
        ->assertSee('When: Any', false);
});
