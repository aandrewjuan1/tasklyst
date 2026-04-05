<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('search query filters tasks by title', function (): void {
    Task::factory()->for($this->user)->create([
        'title' => 'UniqueSearchableTask',
        'start_datetime' => null,
        'end_datetime' => null,
    ]);

    $this->actingAs($this->user);

    Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('searchQuery', 'UniqueSearchable')
        ->assertSee('UniqueSearchableTask');
});

test('search query excludes tasks when no title match', function (): void {
    Task::factory()->for($this->user)->create([
        'title' => 'VisibleTask',
        'start_datetime' => null,
        'end_datetime' => null,
    ]);

    $this->actingAs($this->user);

    Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('searchQuery', 'ZzzNoMatch')
        ->assertDontSee('VisibleTask');
});

test('getFilters includes search state', function (): void {
    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index')
        ->set('searchQuery', '  foo  ');

    $filters = $component->instance()->getFilters();
    expect($filters)->toHaveKeys(['searchQuery', 'hasActiveSearch'])
        ->and($filters['searchQuery'])->toBe('foo')
        ->and($filters['hasActiveSearch'])->toBeTrue();
});

test('clearAllFilters clears search query', function (): void {
    $this->actingAs($this->user);

    Livewire::test('pages::workspace.index')
        ->set('searchQuery', 'something')
        ->call('clearAllFilters')
        ->assertSet('searchQuery', null);
});

test('search query with exact title shows only matching task', function (): void {
    Task::factory()->for($this->user)->create([
        'title' => 'ExactMatchTitle',
        'start_datetime' => null,
        'end_datetime' => null,
    ]);

    Task::factory()->for($this->user)->create([
        'title' => 'OtherTask',
        'start_datetime' => null,
        'end_datetime' => null,
    ]);

    $this->actingAs($this->user);

    Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('searchQuery', 'ExactMatchTitle')
        ->assertSee('ExactMatchTitle')
        ->assertDontSee('OtherTask');
});

test('search query matches task by description', function (): void {
    Task::factory()->for($this->user)->create([
        'title' => 'PlainTitleForDescSearch',
        'description' => 'UniqueDescriptionPhraseAlpha99',
        'start_datetime' => null,
        'end_datetime' => null,
    ]);

    $this->actingAs($this->user);

    Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('searchQuery', 'UniqueDescriptionPhraseAlpha99')
        ->assertSee('PlainTitleForDescSearch');
});

test('search query matches task by tag name', function (): void {
    $tag = Tag::factory()->for($this->user)->create(['name' => 'UniqueWorkspaceTagBeta77']);

    $task = Task::factory()->for($this->user)->create([
        'title' => 'TitleWithoutTagToken',
        'description' => null,
        'start_datetime' => null,
        'end_datetime' => null,
    ]);
    $task->tags()->attach($tag->id);

    $this->actingAs($this->user);

    Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('searchQuery', 'UniqueWorkspaceTagBeta77')
        ->assertSee('TitleWithoutTagToken');
});

test('search query matches task by teacher_name', function (): void {
    Task::factory()->for($this->user)->create([
        'title' => 'HomeworkItemGamma',
        'description' => null,
        'teacher_name' => 'Prof UniqueTeacherGamma44',
        'subject_name' => null,
        'start_datetime' => null,
        'end_datetime' => null,
    ]);

    $this->actingAs($this->user);

    Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('searchQuery', 'UniqueTeacherGamma44')
        ->assertSee('HomeworkItemGamma');
});

test('search query matches task by subject_name', function (): void {
    Task::factory()->for($this->user)->create([
        'title' => 'QuizDelta',
        'description' => null,
        'teacher_name' => null,
        'subject_name' => 'MATH 999 UniqueSubjectDelta',
        'start_datetime' => null,
        'end_datetime' => null,
    ]);

    $this->actingAs($this->user);

    Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('searchQuery', 'UniqueSubjectDelta')
        ->assertSee('QuizDelta');
});

test('search uses OR across tokens: matches when only one token hits', function (): void {
    Task::factory()->for($this->user)->create([
        'title' => 'OrTokenTaskTitle',
        'description' => 'containsfirsttokenonly',
        'start_datetime' => null,
        'end_datetime' => null,
    ]);

    $this->actingAs($this->user);

    Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('searchQuery', 'firsttoken notpresentsecondtokenzz')
        ->assertSee('OrTokenTaskTitle');
});

test('search shows event when only a child task matches', function (): void {
    $event = Event::factory()->for($this->user)->create([
        'title' => 'ZZZParentEventTitleNoMatch',
        'description' => null,
        'start_datetime' => null,
        'end_datetime' => null,
        'status' => EventStatus::Scheduled,
    ]);

    Task::factory()->for($this->user)->create([
        'title' => 'NestedTaskPlain',
        'description' => 'UniqueChildEventMatchToken88',
        'event_id' => $event->id,
        'start_datetime' => null,
        'end_datetime' => null,
    ]);

    $this->actingAs($this->user);

    Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('searchQuery', 'UniqueChildEventMatchToken88')
        ->assertSee('ZZZParentEventTitleNoMatch');
});

test('search shows project when only a child task matches', function (): void {
    $project = Project::factory()->for($this->user)->create([
        'name' => 'ZZZParentProjectNameNoMatch',
        'description' => null,
        'start_datetime' => null,
        'end_datetime' => null,
    ]);

    Task::factory()->for($this->user)->create([
        'title' => 'NestedProjectTaskPlain',
        'description' => 'UniqueChildProjectMatchToken99',
        'project_id' => $project->id,
        'start_datetime' => null,
        'end_datetime' => null,
    ]);

    $this->actingAs($this->user);

    Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('searchQuery', 'UniqueChildProjectMatchToken99')
        ->assertSee('ZZZParentProjectNameNoMatch');
});
