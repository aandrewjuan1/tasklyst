<?php

use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('workspace with view kanban shows Kanban board columns', function (): void {
    $this->actingAs($this->user);

    Livewire::withQueryParams(['view' => 'kanban'])
        ->test('pages::workspace.index')
        ->assertSet('viewMode', 'kanban')
        ->assertSee(__('To Do'))
        ->assertSee(__('Doing'))
        ->assertSee(__('Done'))
        ->assertSee(__('Kanban'));
});

test('creating a task while in kanban view shows it on the board', function (): void {
    $this->actingAs($this->user);

    Livewire::withQueryParams(['view' => 'kanban'])
        ->test('pages::workspace.index')
        ->assertSet('viewMode', 'kanban')
        ->call('createTask', ['title' => 'Kanban created task'])
        ->assertSee('Kanban created task');
});

test('kanban view add control is task-only (no event or project options)', function (): void {
    $this->actingAs($this->user);

    $this->get(route('workspace', ['view' => 'kanban']))
        ->assertSuccessful()
        ->assertDontSee('calendar-days')
        ->assertDontSee('clipboard-document-list');
});

test('workspace view mode can be set to list and kanban', function (): void {
    $this->actingAs($this->user);

    Livewire::test('pages::workspace.index')
        ->assertSet('viewMode', 'list')
        ->set('viewMode', 'kanban')
        ->assertSet('viewMode', 'kanban')
        ->set('viewMode', 'list')
        ->assertSet('viewMode', 'list');
});

test('invalid view mode is normalized to list on mount', function (): void {
    $this->actingAs($this->user);

    Livewire::withQueryParams(['view' => 'invalid'])
        ->test('pages::workspace.index')
        ->assertSet('viewMode', 'list');
});

test('workspace list view mounts only the nested list livewire component', function (): void {
    $this->actingAs($this->user);

    $this->get(route('workspace', ['view' => 'list']))
        ->assertSuccessful()
        ->assertSeeLivewire('pages::workspace.list')
        ->assertDontSeeLivewire('pages::workspace.kanban');
});

test('workspace kanban view mounts only the nested kanban livewire component', function (): void {
    $this->actingAs($this->user);

    $this->get(route('workspace', ['view' => 'kanban']))
        ->assertSuccessful()
        ->assertSeeLivewire('pages::workspace.kanban')
        ->assertDontSeeLivewire('pages::workspace.list');
});

test('switching view mode from list to kanban renders kanban child', function (): void {
    $this->actingAs($this->user);

    Livewire::test('pages::workspace.index')
        ->assertSet('viewMode', 'list')
        ->set('viewMode', 'kanban')
        ->assertSeeLivewire('pages::workspace.kanban')
        ->assertDontSeeLivewire('pages::workspace.list');
});

test('setFilter updates workspace state without requiring list remount counter', function (): void {
    $this->actingAs($this->user);

    Livewire::test('pages::workspace.index')
        ->call('setFilter', 'itemType', 'tasks')
        ->assertSet('filterItemType', 'tasks');
});
