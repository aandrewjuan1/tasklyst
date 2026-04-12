<?php

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
