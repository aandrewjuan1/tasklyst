<?php

use App\Enums\EventStatus;
use App\Enums\TaskSourceType;
use App\Enums\TaskStatus;
use App\Models\Event;
use App\Models\Project;
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
