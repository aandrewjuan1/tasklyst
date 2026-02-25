<?php

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
